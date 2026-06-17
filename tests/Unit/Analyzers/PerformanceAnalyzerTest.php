<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Analyzers;

use CodeGuardian\Laravel\Analyzers\PerformanceAnalyzer;
use PHPUnit\Framework\TestCase;

class PerformanceAnalyzerTest extends TestCase
{
    private PerformanceAnalyzer $analyzer;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new PerformanceAnalyzer();
        $this->tmpDir   = sys_get_temp_dir() . '/cg_perf_scan_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
        parent::tearDown();
    }

    public function test_can_be_instantiated_without_fatal_error(): void
    {
        $this->assertInstanceOf(PerformanceAnalyzer::class, $this->analyzer);
    }

    public function test_analyze_returns_expected_keys(): void
    {
        $file   = $this->tmpFile('empty.php', '<?php // empty');
        $result = $this->analyzer->analyze($file);

        $this->assertArrayHasKey('findings', $result);
        $this->assertArrayHasKey('performance_score', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertIsInt($result['performance_score']);
        $this->assertGreaterThanOrEqual(0, $result['performance_score']);
        $this->assertLessThanOrEqual(100, $result['performance_score']);
    }

    public function test_detects_n_plus_one_query_in_loop(): void
    {
        $file = $this->tmpFile('OrderController.php', <<<'PHP'
        <?php
        class OrderController {
            public function index() {
                $orders = Order::all();
                foreach ($orders as $order) {
                    echo $order->user->name;
                }
            }
        }
        PHP);

        $result = $this->analyzer->analyze($file);
        $this->assertFindsCategory($result['findings'], 'n_plus_one');
    }

    public function test_detects_model_all_without_pagination(): void
    {
        $file = $this->tmpFile('ProductController.php', <<<'PHP'
        <?php
        class ProductController {
            public function index() {
                $products = Product::all();
                return response()->json($products);
            }
        }
        PHP);

        $result = $this->analyzer->analyze($file);
        $this->assertFindsCategory($result['findings'], 'select_all');
    }

    public function test_detects_count_on_collection(): void
    {
        $file = $this->tmpFile('ReportController.php', <<<'PHP'
        <?php
        class ReportController {
            public function stats() {
                $users = User::all();
                $count = count($users);
                return $count;
            }
        }
        PHP);

        $result = $this->analyzer->analyze($file);
        $this->assertFindsCategory($result['findings'], 'inefficient_count');
    }

    public function test_clean_file_scores_high(): void
    {
        $file = $this->tmpFile('CleanController.php', <<<'PHP'
        <?php
        class CleanController {
            public function index() {
                return User::with('profile')->paginate(25);
            }
        }
        PHP);

        $result = $this->analyzer->analyze($file);
        $this->assertGreaterThanOrEqual(80, $result['performance_score']);
    }

    private function tmpFile(string $name, string $content): array
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return [$path => $content];
    }

    private function assertFindsCategory(array $findings, string $category): void
    {
        $categories = array_column($findings, 'category');
        $this->assertContains(
            $category,
            $categories,
            "Expected category '{$category}' but found: " . implode(', ', $categories)
        );
    }

    private function rmdirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
