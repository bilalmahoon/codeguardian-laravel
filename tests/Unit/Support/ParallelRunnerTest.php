<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\ParallelRunner;
use PHPUnit\Framework\TestCase;

class ParallelRunnerTest extends TestCase
{
    public function test_sequential_runs_all_tasks_and_preserves_keys(): void
    {
        $results = ParallelRunner::run([
            'a' => fn() => 1,
            'b' => fn() => 2,
            'c' => fn() => 3,
        ], false);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $results);
    }

    public function test_single_task_runs_inline_even_when_parallel_requested(): void
    {
        $results = ParallelRunner::run(['only' => fn() => 'x'], true);
        $this->assertSame(['only' => 'x'], $results);
    }

    public function test_available_returns_bool(): void
    {
        $this->assertIsBool(ParallelRunner::available());
    }

    public function test_parallel_results_match_sequential_when_supported(): void
    {
        if (! ParallelRunner::available()) {
            $this->markTestSkipped('pcntl not available on this host');
        }

        $tasks = [
            'one'   => fn() => ['n' => 1, 'list' => [1, 2, 3]],
            'two'   => fn() => ['n' => 2, 'list' => ['a' => 'b']],
            'three' => fn() => 'plain string',
        ];

        $parallel   = ParallelRunner::run($tasks, true);
        $sequential = ParallelRunner::run($tasks, false);

        $this->assertSame($sequential, $parallel);
    }
}
