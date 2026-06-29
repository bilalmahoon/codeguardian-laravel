<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Console;

use CodeGuardian\Laravel\Console\Pipeline;
use PHPUnit\Framework\TestCase;

class PipelineTest extends TestCase
{
    private function pipeline(): Pipeline
    {
        // Deterministic clock that advances 1s per call.
        $t = 0.0;
        $clock = function () use (&$t): float {
            $t += 1.0;
            return $t;
        };

        return new Pipeline([
            ['key' => 'a', 'label' => 'Alpha'],
            ['key' => 'b', 'label' => 'Beta'],
        ], $clock);
    }

    public function test_initial_state_is_pending(): void
    {
        $p = $this->pipeline();
        foreach ($p->stages() as $s) {
            $this->assertSame(Pipeline::PENDING, $s['status']);
        }
        $this->assertFalse($p->isComplete());
        $this->assertSame(0, $p->percent());
        $this->assertNull($p->currentStage());
    }

    public function test_running_stage_is_current(): void
    {
        $p = $this->pipeline();
        $p->start('a', 10);
        $this->assertSame('a', $p->currentStage()['key']);
        $this->assertSame(Pipeline::RUNNING, $p->stages()[0]['status']);
    }

    public function test_subprogress_contributes_to_percent(): void
    {
        $p = $this->pipeline();
        $p->start('a', 10);
        $p->advance('a', 5); // half of stage a (1 of 2 stages) → 25%
        $this->assertSame(25, $p->percent());
    }

    public function test_finish_marks_done_and_records_elapsed(): void
    {
        $p = $this->pipeline();
        $p->start('a', 10);
        $p->finish('a', 'note');
        $stage = $p->stages()[0];
        $this->assertSame(Pipeline::DONE, $stage['status']);
        $this->assertSame('note', $stage['note']);
        $this->assertGreaterThan(0, $stage['elapsed']);
        $this->assertSame(50, $p->percent()); // 1 of 2 stages done
    }

    public function test_complete_and_failures(): void
    {
        $p = $this->pipeline();
        $p->start('a');
        $p->finish('a');
        $p->start('b');
        $p->fail('b', 'boom');

        $this->assertTrue($p->isComplete());
        $this->assertTrue($p->hasFailures());
        $this->assertSame(100, $p->percent());
    }

    public function test_skip(): void
    {
        $p = $this->pipeline();
        $p->skip('a', 'not needed');
        $this->assertSame(Pipeline::SKIPPED, $p->stages()[0]['status']);
    }

    public function test_counters_and_current_file(): void
    {
        $p = $this->pipeline();
        $p->incr('high');
        $p->incr('high', 2);
        $p->setCounter('files_total', 17);
        $p->setCurrentFile('app/Foo.php');

        $this->assertSame(3, $p->counter('high'));
        $this->assertSame(17, $p->counter('files_total'));
        $this->assertSame('app/Foo.php', $p->currentFile());
        $this->assertSame(0, $p->counter('missing'));
    }

    public function test_unknown_stage_is_ignored(): void
    {
        $p = $this->pipeline();
        $p->start('does-not-exist');
        $p->finish('does-not-exist');
        $this->assertNull($p->currentStage());
    }
}
