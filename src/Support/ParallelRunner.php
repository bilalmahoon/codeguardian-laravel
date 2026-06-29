<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Runs independent tasks in parallel OS processes via pcntl_fork, falling back
 * to sequential execution when the extension is unavailable or parallelism is
 * disabled.
 *
 * Each task is a closure returning a value that must be serialize()-able (the
 * built-in analyzers return plain arrays, so this holds). Children write their
 * serialized result to a temp file; the parent reaps them and reassembles the
 * results keyed exactly as the input tasks were.
 *
 * This is a best-effort optimisation: any child that dies or returns a corrupt
 * payload simply falls back to the parent re-running that one task inline, so a
 * failure can never lose a result — it only loses the speed-up for that task.
 */
final class ParallelRunner
{
    /** True when real process-level parallelism is possible on this host. */
    public static function available(): bool
    {
        return \function_exists('pcntl_fork')
            && \function_exists('pcntl_waitpid')
            && ! self::isWindows();
    }

    /**
     * Execute $tasks (keyed closures) and return their results in the same keys.
     *
     * @param  array<string,callable> $tasks
     * @param  bool                   $parallel  Force-disable with false.
     * @return array<string,mixed>
     */
    public static function run(array $tasks, bool $parallel = true): array
    {
        if (! $parallel || ! self::available() || count($tasks) < 2) {
            return self::sequential($tasks);
        }

        $tmpDir = sys_get_temp_dir();
        $children = []; // pid => [key, file]
        $results  = [];

        foreach ($tasks as $key => $task) {
            $file = tempnam($tmpDir, 'cg_par_');
            if ($file === false) {
                // Cannot create temp file — run inline as a fallback.
                $results[$key] = $task();
                continue;
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                // Fork failed — run this task inline.
                @unlink($file);
                $results[$key] = $task();
                continue;
            }

            if ($pid === 0) {
                // Child: compute, serialize, write, exit immediately.
                $payload = '';
                try {
                    $payload = serialize(['ok' => true, 'value' => $task()]);
                } catch (\Throwable $e) {
                    $payload = serialize(['ok' => false, 'error' => $e->getMessage()]);
                }
                @file_put_contents($file, $payload);
                // Hard-exit so the child never returns into framework shutdown.
                exit(0);
            }

            // Parent: remember the child.
            $children[$pid] = ['key' => (string) $key, 'file' => $file];
        }

        // Reap all children and collect their payloads.
        foreach ($children as $pid => $meta) {
            pcntl_waitpid($pid, $status);

            $raw     = is_file($meta['file']) ? (string) @file_get_contents($meta['file']) : '';
            @unlink($meta['file']);
            $decoded = $raw !== '' ? @unserialize($raw) : false;

            if (is_array($decoded) && ($decoded['ok'] ?? false) === true) {
                $results[$meta['key']] = $decoded['value'];
            } else {
                // Child failed — re-run the task inline so no result is lost.
                $results[$meta['key']] = $tasks[$meta['key']]();
            }
        }

        // Preserve original task ordering.
        $ordered = [];
        foreach (array_keys($tasks) as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }

    /** @param array<string,callable> $tasks @return array<string,mixed> */
    private static function sequential(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $key => $task) {
            $results[$key] = $task();
        }
        return $results;
    }

    private static function isWindows(): bool
    {
        return \DIRECTORY_SEPARATOR === '\\';
    }
}
