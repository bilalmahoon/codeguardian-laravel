<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\JUnitFormatter;
use PHPUnit\Framework\TestCase;

class JUnitFormatterTest extends TestCase
{
    private function results(): array
    {
        return [
            'all_findings' => [
                ['category' => 'sql_injection', 'severity' => 'critical', 'title' => 'SQLi',
                 'description' => 'User input', 'file' => 'app/X.php', 'line_start' => 10,
                 'recommendation' => 'Use bindings'],
                ['category' => 'n_plus_one', 'severity' => 'medium', 'title' => 'N+1',
                 'file' => 'app/Y.php', 'line_start' => 5],
            ],
        ];
    }

    public function test_is_well_formed_xml(): void
    {
        $xml = (new JUnitFormatter())->format($this->results());
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'output must be valid XML');
    }

    public function test_counts_and_grouping(): void
    {
        $xml = (new JUnitFormatter())->format($this->results());
        $doc = simplexml_load_string($xml);

        $this->assertSame('2', (string) $doc['tests']);
        $this->assertSame('2', (string) $doc['failures']);

        $suites = [];
        foreach ($doc->testsuite as $s) {
            $suites[(string) $s['name']] = (int) $s['tests'];
        }
        $this->assertArrayHasKey('security', $suites);
        $this->assertArrayHasKey('performance', $suites);
    }

    public function test_testcase_has_failure_with_severity_type(): void
    {
        $xml = (new JUnitFormatter())->format($this->results());
        $doc = simplexml_load_string($xml);

        $found = false;
        foreach ($doc->testsuite as $s) {
            foreach ($s->testcase as $tc) {
                if (str_contains((string) $tc['name'], 'SQLi')) {
                    $found = true;
                    $this->assertSame('critical', (string) $tc->failure['type']);
                }
            }
        }
        $this->assertTrue($found, 'SQLi testcase should be present');
    }

    public function test_special_characters_escaped(): void
    {
        $results = ['all_findings' => [[
            'category' => 'xss', 'severity' => 'high',
            'title' => 'Tag <script> & "quotes"', 'description' => 'a < b && c > d',
            'file' => 'app/Z.php', 'line_start' => 1,
        ]]];

        $xml = (new JUnitFormatter())->format($results);
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringNotContainsString('<script>', $xml);
    }
}
