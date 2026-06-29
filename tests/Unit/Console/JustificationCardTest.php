<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Console;

use CodeGuardian\Laravel\Console\JustificationCard;
use PHPUnit\Framework\TestCase;

class JustificationCardTest extends TestCase
{
    public function test_full_finding_renders_all_rows(): void
    {
        $finding = [
            'severity'       => 'critical',
            'title'          => 'Possible OS command injection',
            'root_cause'     => 'Untrusted data reaches an OS command sink.',
            'impact'         => 'Remote code execution on the server.',
            'breaking_risk'  => 'medium',
            'effort'         => 'medium',
            'confidence'     => 'high',
            'owasp'          => 'A03:2021-Injection',
            'cwe'            => 'CWE-78',
            'recommendation' => 'Use escapeshellarg() on every argument.',
        ];

        $text = implode("\n", JustificationCard::lines($finding));

        $this->assertStringContainsString('CRITICAL', $text);
        $this->assertStringContainsString('Possible OS command injection', $text);
        $this->assertStringContainsString('Why', $text);
        $this->assertStringContainsString('Remote code execution', $text);
        $this->assertStringContainsString('Breaking', $text);
        $this->assertStringContainsString('Effort', $text);
        $this->assertStringContainsString('Confidence', $text);
        $this->assertStringContainsString('OWASP A03:2021-Injection', $text);
        $this->assertStringContainsString('CWE-78', $text);
        $this->assertStringContainsString('escapeshellarg', $text);
    }

    public function test_minimal_finding_renders_header_only(): void
    {
        $lines = JustificationCard::lines(['severity' => 'low', 'title' => 'Minor smell']);

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('LOW', $lines[0]);
        $this->assertStringContainsString('Minor smell', $lines[0]);
    }

    public function test_long_text_is_clipped(): void
    {
        $finding = [
            'severity'   => 'high',
            'title'      => 'X',
            'root_cause' => str_repeat('very long reason ', 50),
        ];

        $whyLine = '';
        foreach (JustificationCard::lines($finding) as $line) {
            if (str_contains($line, 'Why')) {
                $whyLine = $line;
            }
        }

        $this->assertStringEndsWith('…', $whyLine);
    }

    public function test_description_used_when_no_root_cause(): void
    {
        $lines = JustificationCard::lines([
            'severity'    => 'medium',
            'title'       => 'Issue',
            'description' => 'A description of the problem.',
        ]);
        $text = implode("\n", $lines);
        $this->assertStringContainsString('A description of the problem.', $text);
    }
}
