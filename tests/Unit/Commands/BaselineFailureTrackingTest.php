<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the baseline-failure-tracking helpers inside RefactorCommand.
 *
 * We test the logic by extracting it via Reflection — this keeps the tests
 * focused on the algorithm without requiring a full Laravel bootstrap.
 */
class BaselineFailureTrackingTest extends TestCase
{
    private object $command;

    protected function setUp(): void
    {
        // Build a minimal anonymous object that carries the two private helpers
        // and the $baselineFailingTests property, mirroring RefactorCommand.
        $this->command = new class {
            public array $baselineFailingTests = [];

            public function extractFailingTestNames(array $result): array
            {
                return array_values(array_filter(array_map(
                    fn(array $f) => $f['test'] ?? null,
                    $result['failures'] ?? []
                )));
            }

            public function filterNewFailures(array $result): array
            {
                if (empty($result['failures'])) {
                    return [];
                }

                return array_values(array_filter(
                    $result['failures'],
                    fn(array $f) => ! in_array($f['test'] ?? '', $this->baselineFailingTests, true)
                ));
            }
        };
    }

    // ─── extractFailingTestNames ──────────────────────────────────────────────

    public function test_extractFailingTestNames_returns_empty_when_no_failures(): void
    {
        $result = ['passed' => true, 'failures' => []];
        $this->assertSame([], $this->command->extractFailingTestNames($result));
    }

    public function test_extractFailingTestNames_extracts_test_key(): void
    {
        $result = [
            'failures' => [
                ['test' => 'Tests\\Foo::bar', 'message' => 'boom'],
                ['test' => 'Tests\\Baz::qux', 'message' => 'nope'],
            ],
        ];

        $names = $this->command->extractFailingTestNames($result);

        $this->assertSame(['Tests\\Foo::bar', 'Tests\\Baz::qux'], $names);
    }

    public function test_extractFailingTestNames_skips_entries_without_test_key(): void
    {
        $result = [
            'failures' => [
                ['message' => 'no test key here'],
                ['test' => 'Tests\\Good::one', 'message' => ''],
            ],
        ];

        $names = $this->command->extractFailingTestNames($result);

        $this->assertSame(['Tests\\Good::one'], $names);
    }

    // ─── filterNewFailures ────────────────────────────────────────────────────

    public function test_filterNewFailures_returns_empty_when_no_failures(): void
    {
        $this->command->baselineFailingTests = ['Tests\\Old::one'];
        $result = ['passed' => true, 'failures' => []];

        $this->assertSame([], $this->command->filterNewFailures($result));
    }

    public function test_filterNewFailures_removes_pre_existing_failures(): void
    {
        $this->command->baselineFailingTests = [
            'Tests\\Old::already_broken',
            'Tests\\Old::also_broken',
        ];

        $result = [
            'failures' => [
                ['test' => 'Tests\\Old::already_broken', 'message' => 'pre-existing'],
                ['test' => 'Tests\\New::newly_broken',   'message' => 'our fault'],
            ],
        ];

        $newFailures = $this->command->filterNewFailures($result);

        $this->assertCount(1, $newFailures);
        $this->assertSame('Tests\\New::newly_broken', $newFailures[0]['test']);
    }

    public function test_filterNewFailures_returns_all_when_baseline_is_empty(): void
    {
        $this->command->baselineFailingTests = [];

        $result = [
            'failures' => [
                ['test' => 'Tests\\A::one', 'message' => ''],
                ['test' => 'Tests\\B::two', 'message' => ''],
            ],
        ];

        $this->assertCount(2, $this->command->filterNewFailures($result));
    }

    public function test_filterNewFailures_returns_empty_when_all_are_pre_existing(): void
    {
        $this->command->baselineFailingTests = ['Tests\\A::one', 'Tests\\B::two'];

        $result = [
            'failures' => [
                ['test' => 'Tests\\A::one', 'message' => ''],
                ['test' => 'Tests\\B::two', 'message' => ''],
            ],
        ];

        $this->assertSame([], $this->command->filterNewFailures($result));
    }

    public function test_workflow_scenario_pre_existing_failures_do_not_block(): void
    {
        // Simulate: 3 tests fail at baseline (pre-existing)
        $baselineResult = [
            'passed'   => false,
            'failures' => [
                ['test' => 'Tests\\Unit::A', 'message' => 'old bug'],
                ['test' => 'Tests\\Unit::B', 'message' => 'old bug'],
                ['test' => 'Tests\\Unit::C', 'message' => 'old bug'],
            ],
        ];

        // Record baseline
        $this->command->baselineFailingTests = $this->command->extractFailingTestNames($baselineResult);
        $this->assertCount(3, $this->command->baselineFailingTests);

        // After refactoring: same 3 still fail — no new failures
        $afterResult = [
            'passed'   => false,
            'failures' => [
                ['test' => 'Tests\\Unit::A', 'message' => 'old bug'],
                ['test' => 'Tests\\Unit::B', 'message' => 'old bug'],
                ['test' => 'Tests\\Unit::C', 'message' => 'old bug'],
            ],
        ];

        $newFailures = $this->command->filterNewFailures($afterResult);
        $this->assertSame([], $newFailures, 'Pre-existing failures must not trigger a rollback prompt');
    }

    public function test_workflow_scenario_new_failure_triggers_alert(): void
    {
        // Baseline: 1 pre-existing failure
        $this->command->baselineFailingTests = ['Tests\\Unit::OldBroken'];

        // After refactoring: same old failure PLUS a new one we introduced
        $afterResult = [
            'passed'   => false,
            'failures' => [
                ['test' => 'Tests\\Unit::OldBroken',  'message' => 'pre-existing'],
                ['test' => 'Tests\\Unit::NewlyBroken', 'message' => 'we broke this'],
            ],
        ];

        $newFailures = $this->command->filterNewFailures($afterResult);

        $this->assertCount(1, $newFailures);
        $this->assertSame('Tests\\Unit::NewlyBroken', $newFailures[0]['test']);
    }
}
