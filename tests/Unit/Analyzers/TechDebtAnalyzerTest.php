<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Analyzers;

use CodeGuardian\Laravel\Analyzers\TechDebtAnalyzer;
use PHPUnit\Framework\TestCase;

class TechDebtAnalyzerTest extends TestCase
{
    private TechDebtAnalyzer $analyzer;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new TechDebtAnalyzer();
        $this->tmpDir   = sys_get_temp_dir() . '/cg_debt_scan_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
        parent::tearDown();
    }

    public function test_can_be_instantiated_without_fatal_error(): void
    {
        $this->assertInstanceOf(TechDebtAnalyzer::class, $this->analyzer);
    }

    public function test_analyze_returns_expected_keys(): void
    {
        $file   = $this->tmpFile('empty.php', '<?php // empty');
        $result = $this->analyzer->analyze($file);

        $this->assertArrayHasKey('findings', $result);
        $this->assertArrayHasKey('tech_debt_score', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    public function test_detects_todo_comments(): void
    {
        $file = $this->tmpFile('Service.php', <<<'PHP'
        <?php
        class Service {
            public function process() {
                // TODO: implement proper error handling
                // FIXME: this is broken when input is null
                return null;
            }
        }
        PHP);

        $result   = $this->analyzer->analyze($file);
        $categories = array_column($result['findings'], 'category');

        $this->assertContains('todo_debt', $categories);
    }

    public function test_detects_missing_return_types(): void
    {
        $file = $this->tmpFile('Helper.php', <<<'PHP'
        <?php
        class Helper {
            public function getValue($key) {
                return config($key);
            }
            public function formatDate($date) {
                return date('Y-m-d', strtotime($date));
            }
            public function slugify($text) {
                return strtolower(str_replace(' ', '-', $text));
            }
            public function truncate($text, $length) {
                return substr($text, 0, $length);
            }
        }
        PHP);

        $result   = $this->analyzer->analyze($file);
        $categories = array_column($result['findings'], 'category');

        $this->assertContains('missing_types', $categories);
    }

    public function test_detects_deeply_nested_code(): void
    {
        $nested = str_repeat('    if (true) {' . "\n", 6) . '        $x = 1;' . "\n" . str_repeat("    }\n", 6);
        $file   = $this->tmpFile('Nested.php', "<?php\nclass Nested {\n    public function run() {\n{$nested}    }\n}");

        $result   = $this->analyzer->analyze($file);
        $categories = array_column($result['findings'], 'category');

        $this->assertContains('deep_nesting', $categories);
    }

    public function test_score_is_valid_integer_in_range(): void
    {
        $file   = $this->tmpFile('any.php', '<?php class Foo {}');
        $result = $this->analyzer->analyze($file);

        $this->assertIsInt($result['tech_debt_score']);
        $this->assertGreaterThanOrEqual(0, $result['tech_debt_score']);
        $this->assertLessThanOrEqual(100, $result['tech_debt_score']);
    }

    private function tmpFile(string $name, string $content): array
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return [$path => $content];
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
