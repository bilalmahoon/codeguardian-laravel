<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\Suppressor;
use PHPUnit\Framework\TestCase;

class SuppressorTest extends TestCase
{
    public function test_config_suppresses_category(): void
    {
        $spec = Suppressor::specFromConfig(['categories' => ['magic_numbers']]);
        $f    = ['category' => 'magic_numbers', 'file' => 'app/X.php', 'severity' => 'low'];

        $this->assertTrue(Suppressor::shouldSuppress($f, $spec, fn() => null));
    }

    public function test_config_suppresses_path_substring(): void
    {
        $spec = Suppressor::specFromConfig(['paths' => ['database/migrations/']]);
        $f    = ['category' => 'x', 'file' => 'database/migrations/2024_create.php'];

        $this->assertTrue(Suppressor::shouldSuppress($f, $spec, fn() => null));
    }

    public function test_config_suppresses_path_glob(): void
    {
        $spec = Suppressor::specFromConfig(['paths' => ['tests/*']]);
        $this->assertTrue(Suppressor::shouldSuppress(['category' => 'x', 'file' => 'tests/Unit/FooTest.php'], $spec, fn() => null));
        $this->assertFalse(Suppressor::shouldSuppress(['category' => 'x', 'file' => 'app/Foo.php'], $spec, fn() => null));
    }

    public function test_inline_bare_marker_on_same_line(): void
    {
        $content = "<?php\n\$x = md5(\$p); // codeguardian-ignore\n";
        $f = ['category' => 'weak_cryptography', 'file' => 'a.php', 'line_start' => 2];

        $this->assertTrue(Suppressor::inlineSuppresses($f, $content));
    }

    public function test_inline_marker_on_line_above(): void
    {
        $content = "<?php\n// codeguardian-ignore\n\$x = md5(\$p);\n";
        $f = ['category' => 'weak_cryptography', 'file' => 'a.php', 'line_start' => 3];

        $this->assertTrue(Suppressor::inlineSuppresses($f, $content));
    }

    public function test_inline_category_specific(): void
    {
        $content = "<?php\n\$x = 1; // codeguardian-ignore magic_numbers\n";
        $match   = ['category' => 'magic_numbers', 'file' => 'a.php', 'line_start' => 2];
        $other   = ['category' => 'sql_injection', 'file' => 'a.php', 'line_start' => 2];

        $this->assertTrue(Suppressor::inlineSuppresses($match, $content));
        $this->assertFalse(Suppressor::inlineSuppresses($other, $content));
    }

    public function test_inline_file_level(): void
    {
        $content = "<?php\n// codeguardian-ignore-file\nclass Foo {}\n\$y = 2;\n";
        $f = ['category' => 'anything', 'file' => 'a.php', 'line_start' => 4];

        $this->assertTrue(Suppressor::inlineSuppresses($f, $content));
    }

    public function test_no_marker_does_not_suppress(): void
    {
        $content = "<?php\n\$x = md5(\$p);\n";
        $f = ['category' => 'weak_cryptography', 'file' => 'a.php', 'line_start' => 2];

        $this->assertFalse(Suppressor::inlineSuppresses($f, $content));
    }

    public function test_apply_to_result_filters_and_recomputes(): void
    {
        $results = [
            'all_findings' => [
                ['category' => 'magic_numbers', 'severity' => 'low', 'file' => 'a.php', 'line_start' => 1],
                ['category' => 'sql_injection', 'severity' => 'critical', 'file' => 'b.php', 'line_start' => 1],
            ],
            'agent_results' => [
                'security'  => ['findings' => [['category' => 'sql_injection', 'severity' => 'critical', 'file' => 'b.php', 'line_start' => 1]]],
                'tech_debt' => ['findings' => [['category' => 'magic_numbers', 'severity' => 'low', 'file' => 'a.php', 'line_start' => 1]]],
            ],
            'summary' => ['total_issues' => 2, 'critical' => 1, 'high' => 0, 'medium' => 0, 'low' => 1],
        ];

        $spec = Suppressor::specFromConfig(['categories' => ['magic_numbers']]);
        [$out, $count] = Suppressor::applyToResult($results, $spec, fn() => null);

        $this->assertSame(1, $count);
        $this->assertCount(1, $out['all_findings']);
        $this->assertSame(1, $out['summary']['total_issues']);
        $this->assertSame(0, $out['summary']['low']);
        $this->assertCount(0, $out['agent_results']['tech_debt']['findings']);
        $this->assertCount(1, $out['agent_results']['security']['findings']);
    }

    public function test_apply_to_result_uses_inline_via_reader(): void
    {
        $results = [
            'all_findings' => [
                ['category' => 'weak_cryptography', 'severity' => 'high', 'file' => 'a.php', 'line_start' => 2],
            ],
            'summary' => ['total_issues' => 1, 'critical' => 0, 'high' => 1, 'medium' => 0, 'low' => 0],
        ];

        $reader = fn(string $f) => $f === 'a.php' ? "<?php\n\$x = md5(\$p); // codeguardian-ignore\n" : null;
        [$out, $count] = Suppressor::applyToResult($results, Suppressor::specFromConfig([]), $reader);

        $this->assertSame(1, $count);
        $this->assertCount(0, $out['all_findings']);
    }
}
