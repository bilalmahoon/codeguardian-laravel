<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\RunStore;
use PHPUnit\Framework\TestCase;

class RunStoreTest extends TestCase
{
    private string $runsDir;
    private string $reportsDir;
    private RunStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $base             = sys_get_temp_dir() . '/cg_runstore_' . uniqid();
        $this->runsDir    = $base . '/runs';
        $this->reportsDir = $base . '/reports';
        mkdir($this->runsDir, 0775, true);
        mkdir($this->reportsDir, 0775, true);
        $this->store = new RunStore($this->runsDir, $this->reportsDir);
    }

    protected function tearDown(): void
    {
        $this->rmdir(dirname($this->runsDir));
        parent::tearDown();
    }

    /** Manually create a run directory (bypassing the process-spawning start()). */
    private function makeRun(string $id, array $meta, string $log = ''): void
    {
        $dir = $this->runsDir . '/' . $id;
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/meta.json', json_encode($meta));
        file_put_contents($dir . '/output.log', $log);
    }

    /** @test */
    public function test_lists_runs_newest_first(): void
    {
        $this->makeRun('2026-01-01_10-00-00_aaaa', ['id' => '2026-01-01_10-00-00_aaaa', 'type' => 'analyze', 'status' => 'completed']);
        $this->makeRun('2026-02-01_10-00-00_bbbb', ['id' => '2026-02-01_10-00-00_bbbb', 'type' => 'refactor', 'status' => 'completed']);

        $runs = $this->store->all();

        $this->assertCount(2, $runs);
        $this->assertSame('2026-02-01_10-00-00_bbbb', $runs[0]['id'], 'Newest run must come first');
    }

    /** @test */
    public function test_running_status_resolves_to_completed_from_sentinel(): void
    {
        $this->makeRun(
            'run_ok',
            ['id' => 'run_ok', 'type' => 'analyze', 'status' => 'running', 'started_at' => date('c')],
            "working...\ndone\nCG_EXIT:0\n"
        );

        $run = $this->store->find('run_ok');

        $this->assertSame('completed', $run['status']);
        $this->assertSame(0, $run['exit_code']);
    }

    /** @test */
    public function test_running_status_resolves_to_failed_from_sentinel(): void
    {
        $this->makeRun(
            'run_bad',
            ['id' => 'run_bad', 'type' => 'refactor', 'status' => 'running', 'started_at' => date('c')],
            "boom\nCG_EXIT:1\n"
        );

        $run = $this->store->find('run_bad');

        $this->assertSame('failed', $run['status']);
        $this->assertSame(1, $run['exit_code']);
    }

    /** @test */
    public function test_running_without_sentinel_stays_running(): void
    {
        $this->makeRun(
            'run_live',
            ['id' => 'run_live', 'type' => 'analyze', 'status' => 'running', 'started_at' => date('c')],
            "still going...\n"
        );

        $this->assertSame('running', $this->store->find('run_live')['status']);
    }

    /** @test */
    public function test_log_tail_returns_only_new_content_from_offset(): void
    {
        $this->makeRun('run_tail', ['id' => 'run_tail', 'type' => 'analyze', 'status' => 'running'], "line1\nline2\n");

        $first = $this->store->logTail('run_tail', 0);
        $this->assertSame("line1\nline2\n", $first['content']);

        // No new content since last offset.
        $second = $this->store->logTail('run_tail', $first['offset']);
        $this->assertSame('', $second['content']);
        $this->assertSame($first['offset'], $second['offset']);
    }

    /** @test */
    public function test_reports_for_matches_only_files_after_start(): void
    {
        $started = time();
        $this->makeRun('run_rep', ['id' => 'run_rep', 'type' => 'analyze', 'status' => 'completed', 'started_at' => date('c', $started)]);

        // An old report (before the run) must be ignored.
        $old = $this->reportsDir . '/old.html';
        file_put_contents($old, '<html>old</html>');
        touch($old, $started - 600);

        // A fresh report (after the run) must be picked up.
        $new = $this->reportsDir . '/scan-fresh.html';
        file_put_contents($new, '<html>fresh</html>');
        touch($new, $started + 5);

        $reports = $this->store->reportsFor($this->store->find('run_rep'));
        $names   = array_column($reports, 'name');

        $this->assertContains('scan-fresh.html', $names);
        $this->assertNotContains('old.html', $names);
    }

    /** @test */
    public function test_delete_removes_run_directory(): void
    {
        $this->makeRun('run_del', ['id' => 'run_del', 'type' => 'analyze', 'status' => 'completed']);
        $this->assertTrue($this->store->exists('run_del'));

        $this->store->delete('run_del');

        $this->assertFalse($this->store->exists('run_del'));
    }

    /** @test */
    public function test_build_arg_string_escapes_and_skips_empties(): void
    {
        $method = new \ReflectionMethod($this->store, 'buildArgString');
        $arg    = $method->invoke($this->store, [
            'api'   => 'v1/auth/login',
            'mode'  => 'auto',
            'safe'  => true,
            'path'  => '',
            'skip'  => false,
            'null'  => null,
        ]);

        $this->assertStringContainsString("--api=", $arg);
        $this->assertStringContainsString("v1/auth/login", $arg);
        $this->assertStringContainsString('--mode=', $arg);
        $this->assertStringContainsString('--safe', $arg);
        $this->assertStringNotContainsString('--path', $arg, 'Empty values must be skipped');
        $this->assertStringNotContainsString('--skip', $arg, 'false flags must be skipped');
        $this->assertStringNotContainsString('--null', $arg, 'null values must be skipped');
    }

    private function rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
