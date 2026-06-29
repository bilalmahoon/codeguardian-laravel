<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\SarifFormatter;
use PHPUnit\Framework\TestCase;

class SarifFormatterTest extends TestCase
{
    private function sampleResults(): array
    {
        return [
            'all_findings' => [
                [
                    'category' => 'sql_injection', 'severity' => 'critical',
                    'title' => 'SQL injection', 'description' => 'User input in query',
                    'file' => 'app/Http/Controllers/UserController.php',
                    'line_start' => 42, 'line_end' => 42,
                    'code_snippet' => 'DB::select("... $id");',
                    'recommendation' => 'Use bindings.',
                    'owasp' => 'A03:2021-Injection', 'cwe' => 'CWE-89', 'confidence' => 'high',
                ],
                [
                    'category' => 'n_plus_one', 'severity' => 'medium',
                    'title' => 'N+1 query', 'description' => 'Loop query',
                    'file' => 'app/Models/Order.php', 'line_start' => 0,
                ],
            ],
        ];
    }

    public function test_top_level_sarif_shape(): void
    {
        $doc = (new SarifFormatter())->build($this->sampleResults());

        $this->assertSame('2.1.0', $doc['version']);
        $this->assertArrayHasKey('$schema', $doc);
        $this->assertCount(1, $doc['runs']);
        $this->assertSame('CodeGuardian AI', $doc['runs'][0]['tool']['driver']['name']);
    }

    public function test_rules_are_deduped_by_category(): void
    {
        $results = $this->sampleResults();
        $results['all_findings'][] = [
            'category' => 'sql_injection', 'severity' => 'high',
            'title' => 'Another SQLi', 'file' => 'app/Y.php', 'line_start' => 5,
        ];

        $doc   = (new SarifFormatter())->build($results);
        $rules = $doc['runs'][0]['tool']['driver']['rules'];

        $ids = array_column($rules, 'id');
        $this->assertContains('sql_injection', $ids);
        $this->assertContains('n_plus_one', $ids);
        $this->assertSame(count($ids), count(array_unique($ids)), 'rules must be unique by category');
    }

    public function test_result_level_and_security_severity_mapping(): void
    {
        $doc     = (new SarifFormatter())->build($this->sampleResults());
        $results = $doc['runs'][0]['results'];

        $this->assertSame('error', $results[0]['level']);    // critical
        $this->assertSame('warning', $results[1]['level']);  // medium
        $this->assertSame('9.5', $results[0]['properties']['security-severity']);
    }

    public function test_start_line_clamped_to_minimum_one(): void
    {
        $doc = (new SarifFormatter())->build($this->sampleResults());
        $region = $doc['runs'][0]['results'][1]['locations'][0]['physicalLocation']['region'];
        $this->assertSame(1, $region['startLine']); // line_start 0 → 1 (SARIF requires >= 1)
    }

    public function test_partial_fingerprints_present(): void
    {
        $doc = (new SarifFormatter())->build($this->sampleResults());
        $this->assertArrayHasKey('codeguardian/v1', $doc['runs'][0]['results'][0]['partialFingerprints']);
    }

    public function test_uri_is_relative_with_forward_slashes(): void
    {
        $results = ['all_findings' => [[
            'category' => 'x', 'severity' => 'low', 'title' => 'T',
            'file' => '\\app\\Foo.php', 'line_start' => 1,
        ]]];
        $doc = (new SarifFormatter())->build($results);
        $uri = $doc['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri'];
        $this->assertSame('app/Foo.php', $uri);
    }

    public function test_format_returns_valid_json(): void
    {
        $json = (new SarifFormatter())->format($this->sampleResults());
        $decoded = json_decode($json, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('2.1.0', $decoded['version']);
    }

    public function test_falls_back_to_agent_results(): void
    {
        $results = ['agent_results' => [
            'security' => ['findings' => [[
                'category' => 'xss', 'severity' => 'high', 'title' => 'XSS', 'file' => 'a.php', 'line_start' => 3,
            ]]],
            'qa' => ['findings' => [['category' => 'ignore', 'severity' => 'low', 'title' => 'skip', 'file' => 'b.php']]],
        ]];

        $doc = (new SarifFormatter())->build($results);
        $this->assertCount(1, $doc['runs'][0]['results']); // qa excluded
        $this->assertSame('xss', $doc['runs'][0]['results'][0]['ruleId']);
    }

    public function test_cwe_produces_help_uri(): void
    {
        $doc   = (new SarifFormatter())->build($this->sampleResults());
        $rules = $doc['runs'][0]['tool']['driver']['rules'];
        $sqli  = array_values(array_filter($rules, fn($r) => $r['id'] === 'sql_injection'))[0];
        $this->assertSame('https://cwe.mitre.org/data/definitions/89.html', $sqli['helpUri']);
    }

    public function test_code_after_emits_suggested_fix(): void
    {
        $results = ['all_findings' => [[
            'category' => 'sql_injection', 'severity' => 'high', 'title' => 'SQLi',
            'file' => 'app/X.php', 'line_start' => 10, 'line_end' => 10,
            'recommendation' => 'Use bindings.',
            'code_after' => 'DB::select("...", [$id]);',
        ]]];

        $doc    = (new SarifFormatter())->build($results);
        $result = $doc['runs'][0]['results'][0];

        $this->assertArrayHasKey('fixes', $result);
        $replacement = $result['fixes'][0]['artifactChanges'][0]['replacements'][0];
        $this->assertSame(10, $replacement['deletedRegion']['startLine']);
        $this->assertSame('DB::select("...", [$id]);', $replacement['insertedContent']['text']);
    }

    public function test_no_fix_when_no_code_after(): void
    {
        $doc = (new SarifFormatter())->build($this->sampleResults());
        $this->assertArrayNotHasKey('fixes', $doc['runs'][0]['results'][0]);
    }
}
