<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Persists CodeGuardian "runs" (analyze / security / performance / refactor /
 * test generation) to the filesystem so the web dashboard can:
 *
 *   - launch an operation as a detached background process,
 *   - stream its live console output to the browser (log polling), and
 *   - browse the full history of past runs and their results.
 *
 * Zero infrastructure: no queue, no websockets, no database. Each run is a
 * directory under storage/codeguardian/runs/{id}/ containing:
 *
 *   meta.json    run metadata (type, label, command, status, timestamps, exit)
 *   output.log   live stdout+stderr of the background process
 *
 * Completion is detected by a sentinel line "CG_EXIT:<code>" appended by the
 * launching shell, so we never depend on a long-lived PHP process.
 */
class RunStore
{
    private const SENTINEL = 'CG_EXIT:';

    public function __construct(
        private readonly string $runsDir,
        private readonly string $reportsDir,
        private readonly ?string $phpBinary = null,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            storage_path((string) config('codeguardian.dashboard.runs_dir', 'codeguardian/runs')),
            storage_path((string) config('codeguardian.output.report_dir', 'codeguardian/reports')),
            config('codeguardian.dashboard.php_binary'),
        );
    }

    // ─── Creation & launch ────────────────────────────────────────────────────

    /**
     * Create a new run record and launch the artisan command in the background.
     *
     * @param  string               $type     analyze|security|performance|refactor|generate-tests
     * @param  string               $artisan  artisan command name (e.g. codeguardian:refactor)
     * @param  array<string,string|bool|null> $options  CLI options ([--key => value])
     * @param  string               $label    human-readable label for the history list
     * @return string  the run id
     */
    public function start(string $type, string $artisan, array $options, string $label): string
    {
        $id  = date('Y-m-d_H-i-s') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $dir = $this->dir($id);
        $this->ensureDir($dir);

        $argString = $this->buildArgString($options);
        $log       = $dir . '/output.log';

        $meta = [
            'id'          => $id,
            'type'        => $type,
            'label'       => $label,
            'artisan'     => $artisan,
            'options'     => $options,
            'command'     => trim("php artisan {$artisan} {$argString}"),
            'status'      => 'running',
            'exit_code'   => null,
            'started_at'  => date('c'),
            'finished_at' => null,
        ];
        $this->writeMeta($id, $meta);

        file_put_contents($log, "$ {$meta['command']}\n\n");

        $this->launch($artisan, $argString, $log);

        return $id;
    }

    /**
     * Launch `php artisan <cmd> <args>` as a detached background process whose
     * combined output streams into $log, with a completion sentinel appended.
     */
    private function launch(string $artisan, string $argString, string $log): void
    {
        $php     = $this->phpBinary ?: (PHP_BINARY ?: 'php');
        $artisanPath = base_path('artisan');

        // Force non-interactive, decoration-free output so the log stays clean
        // and the command never blocks waiting for stdin.
        $base = sprintf(
            '%s %s %s --no-interaction --no-ansi',
            escapeshellarg($php),
            escapeshellarg($artisanPath),
            $artisan . ($argString !== '' ? ' ' . $argString : '')
        );

        // { run ; echo sentinel:$? ; } >> log 2>&1 &   — fully detached via nohup.
        $shell = sprintf(
            'nohup sh -c %s >> %s 2>&1 &',
            escapeshellarg(sprintf('{ %s ; echo "%s$?" ; }', $base, self::SENTINEL)),
            escapeshellarg($log)
        );

        // proc_open detaches cleanly; we immediately close the handles so the
        // child outlives the web request.
        $handle = @proc_open($shell, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, base_path());

        if (is_resource($handle)) {
            proc_close($handle);
        }
    }

    // ─── Reading ──────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> newest first */
    public function all(): array
    {
        if (! is_dir($this->runsDir)) {
            return [];
        }

        $runs = [];
        foreach ((array) glob(rtrim($this->runsDir, '/') . '/*', GLOB_ONLYDIR) as $dir) {
            $id   = basename((string) $dir);
            $meta = $this->readMeta($id);
            if ($meta !== null) {
                $runs[] = $this->refreshStatus($meta);
            }
        }

        usort($runs, fn($a, $b) => strcmp($b['id'] ?? '', $a['id'] ?? ''));

        return $runs;
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $meta = $this->readMeta($id);

        return $meta === null ? null : $this->refreshStatus($meta);
    }

    public function exists(string $id): bool
    {
        return $this->readMeta($id) !== null;
    }

    /**
     * Return log content from a byte offset (for incremental polling).
     *
     * @return array{content:string,offset:int}
     */
    public function logTail(string $id, int $offset = 0): array
    {
        $log = $this->dir($id) . '/output.log';
        if (! file_exists($log)) {
            return ['content' => '', 'offset' => 0];
        }

        $size = (int) (@filesize($log) ?: 0);
        if ($offset < 0 || $offset > $size) {
            $offset = 0;
        }

        $content = '';
        if ($size > $offset) {
            $fh = @fopen($log, 'rb');
            if ($fh !== false) {
                fseek($fh, $offset);
                $content = (string) stream_get_contents($fh);
                fclose($fh);
            }
        }

        return ['content' => $content, 'offset' => $size];
    }

    public function fullLog(string $id): string
    {
        $log = $this->dir($id) . '/output.log';

        return file_exists($log) ? (string) @file_get_contents($log) : '';
    }

    /**
     * Report files (json/html) produced by this run — detected by matching the
     * reports directory against files modified at/after the run start time.
     *
     * @return array<int,array{name:string,path:string,ext:string}>
     */
    public function reportsFor(array $meta): array
    {
        if (! is_dir($this->reportsDir)) {
            return [];
        }

        $startedTs = strtotime($meta['started_at'] ?? 'now') ?: 0;
        $reports   = [];

        foreach ((array) glob(rtrim($this->reportsDir, '/') . '/*') as $path) {
            $path = (string) $path;
            if (! is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (! in_array($ext, ['html', 'json'], true)) {
                continue;
            }
            // 2s grace so a report written just before meta flush still matches.
            if (((int) (@filemtime($path) ?: 0)) + 2 < $startedTs) {
                continue;
            }
            $reports[] = [
                'name' => basename($path),
                'path' => $path,
                'ext'  => $ext,
            ];
        }

        usort($reports, fn($a, $b) => strcmp($b['name'], $a['name']));

        return $reports;
    }

    /**
     * Distinct files (with finding counts) from a run's JSON report, most issues
     * first. Powers the dashboard "fix selected files" UI.
     *
     * @param array<string,mixed> $meta
     * @return array<int,array{file:string,count:int}>
     */
    public function reportFiles(array $meta): array
    {
        foreach ($this->reportsFor($meta) as $report) {
            if ($report['ext'] !== 'json') {
                continue;
            }
            $data = json_decode((string) @file_get_contents($report['path']), true);
            if (! is_array($data)) {
                continue;
            }

            $counts = [];
            foreach (($data['all_findings'] ?? []) as $f) {
                $file = (string) ($f['file'] ?? '');
                if ($file !== '') {
                    $counts[$file] = ($counts[$file] ?? 0) + 1;
                }
            }
            arsort($counts);

            $out = [];
            foreach ($counts as $file => $count) {
                $out[] = ['file' => $file, 'count' => $count];
            }
            return $out;
        }

        return [];
    }

    /**
     * Decode a run's JSON report (summary, findings, quality, scores) for the
     * dashboard findings explorer. Returns null when no JSON report exists.
     *
     * @param  array<string,mixed> $meta
     * @return array<string,mixed>|null
     */
    public function reportData(array $meta): ?array
    {
        foreach ($this->reportsFor($meta) as $report) {
            if ($report['ext'] !== 'json') {
                continue;
            }
            $data = json_decode((string) @file_get_contents($report['path']), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    public function reportHtml(string $id): ?string
    {
        $meta = $this->readMeta($id);
        if ($meta === null) {
            return null;
        }
        foreach ($this->reportsFor($meta) as $report) {
            if ($report['ext'] === 'html') {
                return (string) @file_get_contents($report['path']);
            }
        }

        return null;
    }

    public function delete(string $id): void
    {
        $this->deleteDir($this->dir($id));
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    private function dir(string $id): string
    {
        // Defensive: ids are generated internally, but never allow traversal.
        $id = preg_replace('/[^A-Za-z0-9_\-]/', '', $id) ?: 'invalid';

        return rtrim($this->runsDir, '/') . '/' . $id;
    }

    private function writeMeta(string $id, array $meta): void
    {
        $this->ensureDir($this->dir($id));
        file_put_contents(
            $this->dir($id) . '/meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /** @return array<string,mixed>|null */
    private function readMeta(string $id): ?array
    {
        $path = $this->dir($id) . '/meta.json';
        if (! file_exists($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob(rtrim($dir, '/') . '/*') as $item) {
            $item = (string) $item;
            is_dir($item) ? $this->deleteDir($item) : @unlink($item);
        }
        @rmdir($dir);
    }

    /**
     * Reconcile a "running" meta with reality by inspecting the log sentinel.
     * Persists the transition to finished/failed the first time it is observed.
     */
    private function refreshStatus(array $meta): array
    {
        if (($meta['status'] ?? null) !== 'running') {
            return $meta;
        }

        $log = $this->fullLog($meta['id']);
        $pos = strrpos($log, self::SENTINEL);
        if ($pos !== false) {
            $code = (int) trim(substr($log, $pos + strlen(self::SENTINEL)));
            $meta['status']      = $code === 0 ? 'completed' : 'failed';
            $meta['exit_code']   = $code;
            $meta['finished_at'] = date('c');
            $this->writeMeta($meta['id'], $meta);
        }

        return $meta;
    }

    /** @param array<string,string|bool|null> $options */
    private function buildArgString(array $options): string
    {
        $parts = [];
        foreach ($options as $key => $value) {
            if ($value === null || $value === false || $value === '') {
                continue;
            }
            if ($value === true) {
                $parts[] = "--{$key}";
                continue;
            }
            $parts[] = "--{$key}=" . escapeshellarg((string) $value);
        }

        return implode(' ', $parts);
    }
}
