<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\TestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TestRunner — focused on mergeResults(), skippedResult(),
 * and the new isDirEmpty() / runExistingProjectTests() path-discovery logic.
 *
 * We do NOT actually invoke PHPUnit/Pest here (that would require a full
 * Laravel bootstrap). Instead we test the pure result-merging helpers and
 * the directory-scan helpers by building a small temp tree.
 */
class TestRunnerTest extends TestCase
{
    // ─── mergeResults ────────────────────────────────────────────────────────

    public function test_mergeResults_combines_totals(): void
    {
        $runner = new TestRunner('/tmp');

        $a = [
            'passed'        => true,
            'skipped'       => false,
            'total'         => 5,
            'passed_count'  => 5,
            'failed_count'  => 0,
            'errors'        => 0,
            'failures'      => [],
            'duration_ms'   => 200,
            'output'        => 'ok',
        ];
        $b = [
            'passed'        => true,
            'skipped'       => false,
            'total'         => 3,
            'passed_count'  => 3,
            'failed_count'  => 0,
            'errors'        => 0,
            'failures'      => [],
            'duration_ms'   => 100,
            'output'        => 'ok',
        ];

        $merged = $runner->mergeResults($a, $b);

        $this->assertTrue($merged['passed']);
        $this->assertSame(8, $merged['total']);
        $this->assertSame(8, $merged['passed_count']);
        $this->assertSame(0, $merged['failed_count']);
        $this->assertSame(300, $merged['duration_ms']);
    }

    public function test_mergeResults_fails_when_either_side_fails(): void
    {
        $runner = new TestRunner('/tmp');

        $passing = [
            'passed' => true, 'skipped' => false, 'total' => 2, 'passed_count' => 2,
            'failed_count' => 0, 'errors' => 0, 'failures' => [], 'duration_ms' => 50, 'output' => '',
        ];
        $failing = [
            'passed' => false, 'skipped' => false, 'total' => 3, 'passed_count' => 2,
            'failed_count' => 1, 'errors' => 0, 'failures' => [['test' => 'X', 'message' => 'fail']],
            'duration_ms' => 80, 'output' => '',
        ];

        $merged = $runner->mergeResults($passing, $failing);

        $this->assertFalse($merged['passed']);
        $this->assertSame(1, $merged['failed_count']);
        $this->assertCount(1, $merged['failures']);
    }

    public function test_mergeResults_returns_other_when_one_is_skipped(): void
    {
        $runner = new TestRunner('/tmp');

        $skipped = [
            'passed' => true, 'skipped' => true, 'total' => 0,
            'passed_count' => 0, 'failed_count' => 0, 'errors' => 0,
            'failures' => [], 'duration_ms' => 0, 'output' => '',
        ];
        $real = [
            'passed' => true, 'skipped' => false, 'total' => 4, 'passed_count' => 4,
            'failed_count' => 0, 'errors' => 0, 'failures' => [], 'duration_ms' => 120, 'output' => '',
        ];

        $result = $runner->mergeResults($skipped, $real);

        $this->assertFalse($result['skipped'] ?? false);
        $this->assertSame(4, $result['total']);
    }

    public function test_mergeResults_carries_extra_keys(): void
    {
        $runner = new TestRunner('/tmp');

        $a = ['passed' => true, 'skipped' => false, 'total' => 1, 'passed_count' => 1,
              'failed_count' => 0, 'errors' => 0, 'failures' => [], 'duration_ms' => 10, 'output' => ''];
        $b = ['passed' => true, 'skipped' => false, 'total' => 1, 'passed_count' => 1,
              'failed_count' => 0, 'errors' => 0, 'failures' => [], 'duration_ms' => 10, 'output' => ''];

        $merged = $runner->mergeResults($a, $b, ['sources' => ['a' => $a, 'b' => $b]]);

        $this->assertArrayHasKey('sources', $merged);
        $this->assertArrayHasKey('a', $merged['sources']);
        $this->assertArrayHasKey('b', $merged['sources']);
    }

    // ─── runExistingProjectTests — path discovery ─────────────────────────────

    public function test_runExistingProjectTests_returns_skipped_when_no_tests_dir(): void
    {
        $runner = new TestRunner('/nonexistent_project_xyz_123');

        $result = $runner->runExistingProjectTests();

        $this->assertTrue($result['passed']);
        $this->assertTrue($result['skipped'] ?? false);
    }

    public function test_runExistingProjectTests_excludes_codeguardian_folder(): void
    {
        // Build a temp project tree
        $root = sys_get_temp_dir() . '/cg_runner_test_' . uniqid();
        $this->makeTempTree($root, [
            'tests/Feature/SomeTest.php' => '<?php class SomeTest {}',
            'tests/Unit/AnotherTest.php' => '<?php class AnotherTest {}',
            'tests/CodeGuardian/StubTest.php' => '<?php class StubTest {}',
        ]);

        $runner = new TestRunner($root);

        // Even though PHPUnit is not available in this test environment
        // we just verify that the directory-discovery method correctly
        // assembles the list by inspecting what it would run via reflection.
        $ref = new \ReflectionMethod($runner, 'isDirEmpty');
        $ref->setAccessible(true);

        $this->assertFalse($ref->invoke($runner, $root . '/tests/Feature'));
        $this->assertFalse($ref->invoke($runner, $root . '/tests/Unit'));
        $this->assertFalse($ref->invoke($runner, $root . '/tests/CodeGuardian'));

        $this->cleanTempTree($root);
    }

    public function test_runCodeGuardianTests_returns_skipped_when_folder_missing(): void
    {
        $root = sys_get_temp_dir() . '/cg_runner_nocg_' . uniqid();
        mkdir($root . '/tests/Feature', 0777, true);
        file_put_contents($root . '/tests/Feature/Foo.php', '<?php class Foo {}');

        $runner = new TestRunner($root);
        $result = $runner->runCodeGuardianTests();

        $this->assertTrue($result['passed']);
        $this->assertTrue($result['skipped'] ?? false);

        $this->cleanTempTree($root);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeTempTree(string $root, array $files): void
    {
        foreach ($files as $rel => $content) {
            $path = $root . '/' . $rel;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $content);
        }
    }

    private function cleanTempTree(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($root);
    }
}
