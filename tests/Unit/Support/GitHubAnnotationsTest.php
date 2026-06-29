<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\GitHubAnnotations;
use PHPUnit\Framework\TestCase;

class GitHubAnnotationsTest extends TestCase
{
    public function test_level_mapping(): void
    {
        $this->assertStringStartsWith('::error ', GitHubAnnotations::line(['severity' => 'critical', 'file' => 'a.php', 'line_start' => 1, 'title' => 'X']));
        $this->assertStringStartsWith('::error ', GitHubAnnotations::line(['severity' => 'high', 'file' => 'a.php', 'line_start' => 1, 'title' => 'X']));
        $this->assertStringStartsWith('::warning ', GitHubAnnotations::line(['severity' => 'medium', 'file' => 'a.php', 'line_start' => 1, 'title' => 'X']));
        $this->assertStringStartsWith('::notice ', GitHubAnnotations::line(['severity' => 'low', 'file' => 'a.php', 'line_start' => 1, 'title' => 'X']));
    }

    public function test_line_contains_file_line_and_title(): void
    {
        $line = GitHubAnnotations::line([
            'severity' => 'high', 'file' => 'app/Http/X.php', 'line_start' => 42,
            'title' => 'SQL injection', 'cwe' => 'CWE-89', 'description' => 'User input',
        ]);

        $this->assertStringContainsString('file=app/Http/X.php', $line);
        $this->assertStringContainsString('line=42', $line);
        $this->assertStringContainsString('title=CodeGuardian CWE-89%3A SQL injection', $line);
        $this->assertStringContainsString('::User input', $line);
    }

    public function test_message_escaping(): void
    {
        $line = GitHubAnnotations::line([
            'severity' => 'low', 'file' => 'a.php', 'line_start' => 1,
            'title' => 'T', 'description' => "line1\nline2 100%",
        ]);

        $this->assertStringContainsString('line1%0Aline2 100%25', $line);
        $this->assertStringNotContainsString("\n", explode('::', $line, 3)[2] ?? '');
    }

    public function test_property_escaping_for_commas_and_colons(): void
    {
        $line = GitHubAnnotations::line([
            'severity' => 'low', 'file' => 'a,b:c.php', 'line_start' => 1, 'title' => 'x',
        ]);
        $this->assertStringContainsString('file=a%2Cb%3Ac.php', $line);
    }

    public function test_lines_sorted_by_severity_and_capped(): void
    {
        $results = ['all_findings' => [
            ['severity' => 'low', 'file' => 'a.php', 'line_start' => 1, 'title' => 'L'],
            ['severity' => 'critical', 'file' => 'b.php', 'line_start' => 2, 'title' => 'C'],
            ['severity' => 'medium', 'file' => 'c.php', 'line_start' => 3, 'title' => 'M'],
        ]];

        $lines = GitHubAnnotations::lines($results, 2);
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('::error', $lines[0]);   // critical first
    }
}
