<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Executes PHPUnit / Flutter tests and returns structured results.
 */
class TestRunner
{
    private string $projectRoot;
    private int    $timeout;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->timeout     = config('codeguardian.analysis.test_timeout', 120);
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Run tests at a specific path (file or directory).
     *
     * @return array{
     *   passed: bool,
     *   total: int,
     *   passed_count: int,
     *   failed_count: int,
     *   errors: int,
     *   failures: array,
     *   output: string,
     *   duration_ms: int,
     *   exit_code: int
     * }
     */
    public function run(string $testPath, string $type = 'laravel'): array
    {
        $start   = microtime(true);
        $command = $this->buildCommand($testPath, $type);
        $output  = $this->execute($command);
        $ms      = (int) round((microtime(true) - $start) * 1000);

        return array_merge($this->parse($output['stdout'] . $output['stderr'], $type), [
            'output'      => $output['stdout'] . $output['stderr'],
            'duration_ms' => $ms,
            'exit_code'   => $output['exit_code'],
            'command'     => $command,
        ]);
    }

    /**
     * Detect whether PHPUnit/Pest is available.
     */
    public function isAvailable(string $type = 'laravel'): bool
    {
        if ($type === 'flutter') {
            return ! empty(trim(shell_exec('which flutter 2>/dev/null') ?? ''));
        }

        return file_exists($this->projectRoot . '/vendor/bin/phpunit')
            || file_exists($this->projectRoot . '/vendor/bin/pest')
            || ! empty(trim(shell_exec('which phpunit 2>/dev/null') ?? ''));
    }

    /**
     * Run a quick smoke test to make sure existing tests still pass.
     */
    public function smokeTest(): array
    {
        $testDir = $this->projectRoot . '/tests';
        if (! is_dir($testDir)) {
            return ['passed' => true, 'total' => 0, 'message' => 'No test directory found'];
        }

        return $this->run($testDir);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function buildCommand(string $testPath, string $type): string
    {
        if ($type === 'flutter') {
            return "cd {$this->projectRoot} && flutter test " . escapeshellarg($testPath) . " 2>&1";
        }

        // Prefer Pest → PHPUnit → artisan test
        if (file_exists($this->projectRoot . '/vendor/bin/pest')) {
            $bin = './vendor/bin/pest';
        } elseif (file_exists($this->projectRoot . '/vendor/bin/phpunit')) {
            $bin = './vendor/bin/phpunit';
        } else {
            $bin = 'php artisan test';
        }

        // Check if testPath is relative — prepend project root if not absolute
        if (! str_starts_with($testPath, '/')) {
            $testPath = $this->projectRoot . '/' . $testPath;
        }

        return "cd {$this->projectRoot} && {$bin} " . escapeshellarg($testPath)
            . " --colors=never --no-coverage 2>&1";
    }

    private function execute(string $command): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptor, $pipes, $this->projectRoot, null, [
            'bypass_shell' => false,
        ]);

        if (! is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Failed to start process', 'exit_code' => 1];
        }

        fclose($pipes[0]);

        // Apply timeout
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout    = '';
        $stderr    = '';
        $startTime = microtime(true);

        while (true) {
            $stdout .= fread($pipes[1], 4096);
            $stderr .= fread($pipes[2], 4096);

            $status = proc_get_status($process);

            if (! $status['running']) {
                // Drain remaining output
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }

            if ((microtime(true) - $startTime) > $this->timeout) {
                proc_terminate($process, 9);
                $stderr .= "\n[CodeGuardian] Test run timed out after {$this->timeout}s";
                break;
            }

            usleep(100_000); // 100ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
    }

    private function parse(string $output, string $type): array
    {
        if ($type === 'flutter') {
            return $this->parseFlutter($output);
        }
        return $this->parsePHPUnit($output);
    }

    private function parsePHPUnit(string $output): array
    {
        $passed      = false;
        $total       = 0;
        $passedCount = 0;
        $failedCount = 0;
        $errors      = 0;
        $failures    = [];

        // PHPUnit summary line: "Tests: 5, Assertions: 12, Passed: 5"
        // Or older format: "OK (5 tests, 12 assertions)"
        if (preg_match('/OK\s+\((\d+)\s+tests?/', $output, $m)) {
            $total       = (int) $m[1];
            $passedCount = $total;
            $passed      = true;
        } elseif (preg_match('/Tests:\s*(\d+).*?Assertions:\s*(\d+)/s', $output, $m)) {
            $total = (int) $m[1];
        }

        // Failed line: "FAILURES! Tests: 5, Assertions: 12, Failures: 2, Errors: 1"
        if (preg_match('/Failures:\s*(\d+)/i', $output, $m)) {
            $failedCount = (int) $m[1];
        }
        if (preg_match('/Errors:\s*(\d+)/i', $output, $m)) {
            $errors = (int) $m[1];
        }

        $passedCount = $passedCount ?: max(0, $total - $failedCount - $errors);
        $passed      = ($failedCount === 0 && $errors === 0 && $total > 0) || str_contains($output, 'OK (');

        // Extract individual failure messages
        preg_match_all('/\d+\)\s+(.+?)\n(.+?)\nFailed/s', $output, $failMatches);
        foreach ($failMatches[1] as $i => $testName) {
            $failures[] = [
                'test'    => trim($testName),
                'message' => trim($failMatches[2][$i] ?? ''),
            ];
        }

        return [
            'passed'        => $passed,
            'total'         => $total,
            'passed_count'  => $passedCount,
            'failed_count'  => $failedCount,
            'errors'        => $errors,
            'failures'      => $failures,
        ];
    }

    private function parseFlutter(string $output): array
    {
        $passed      = str_contains($output, 'All tests passed') || str_contains($output, '+');
        $total       = 0;
        $passedCount = 0;
        $failedCount = 0;
        $failures    = [];

        // Flutter: "+5: All tests passed!"  or  "+3 -1: Some tests failed"
        if (preg_match('/\+(\d+)(?:\s+-(\d+))?/', $output, $m)) {
            $passedCount = (int) $m[1];
            $failedCount = isset($m[2]) ? (int) $m[2] : 0;
            $total       = $passedCount + $failedCount;
            $passed      = $failedCount === 0;
        }

        return [
            'passed'        => $passed,
            'total'         => $total,
            'passed_count'  => $passedCount,
            'failed_count'  => $failedCount,
            'errors'        => 0,
            'failures'      => $failures,
        ];
    }
}
