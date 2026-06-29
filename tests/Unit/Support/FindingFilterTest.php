<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\FindingFilter;
use PHPUnit\Framework\TestCase;

class FindingFilterTest extends TestCase
{
    private array $findings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->findings = [
            ['category' => 'sql_injection', 'severity' => 'critical', 'confidence' => 'high',   'owasp' => 'A03:2021-Injection', 'cwe' => 'CWE-89'],
            ['category' => 'n_plus_one',    'severity' => 'high',     'confidence' => 'medium',  'owasp' => '',                  'cwe' => ''],
            ['category' => 'magic_numbers', 'severity' => 'low',      'confidence' => 'low',     'owasp' => '',                  'cwe' => ''],
            ['category' => 'xss',           'severity' => 'medium',   'confidence' => 'medium',  'owasp' => 'A03:2021-Injection', 'cwe' => 'CWE-79'],
        ];
    }

    public function test_empty_spec_returns_everything(): void
    {
        $spec = FindingFilter::fromOptions([]);
        $this->assertTrue(FindingFilter::isEmpty($spec));
        $this->assertCount(4, FindingFilter::apply($this->findings, $spec));
    }

    public function test_filters_by_exact_severity_csv(): void
    {
        $spec = FindingFilter::fromOptions(['severity' => 'critical,high']);
        $out  = FindingFilter::apply($this->findings, $spec);

        $this->assertCount(2, $out);
        $this->assertEqualsCanonicalizing(['sql_injection', 'n_plus_one'], array_column($out, 'category'));
    }

    public function test_min_severity_keeps_at_or_above(): void
    {
        $spec = FindingFilter::fromOptions(['min-severity' => 'high']);
        $out  = FindingFilter::apply($this->findings, $spec);

        $this->assertCount(2, $out); // critical + high
        $this->assertEqualsCanonicalizing(['sql_injection', 'n_plus_one'], array_column($out, 'category'));
    }

    public function test_filters_by_confidence(): void
    {
        $spec = FindingFilter::fromOptions(['confidence' => 'high']);
        $out  = FindingFilter::apply($this->findings, $spec);

        $this->assertCount(1, $out);
        $this->assertSame('sql_injection', $out[0]['category']);
    }

    public function test_filters_by_category_substring(): void
    {
        $spec = FindingFilter::fromOptions(['category' => 'injection']);
        $out  = FindingFilter::apply($this->findings, $spec);

        $this->assertCount(1, $out);
        $this->assertSame('sql_injection', $out[0]['category']);
    }

    public function test_filters_by_owasp_substring(): void
    {
        $spec = FindingFilter::fromOptions(['owasp' => 'A03']);
        $out  = FindingFilter::apply($this->findings, $spec);

        $this->assertCount(2, $out); // sql_injection + xss
    }

    public function test_filters_by_cwe(): void
    {
        $spec = FindingFilter::fromOptions(['cwe' => 'CWE-79']);
        $out  = FindingFilter::apply($this->findings, $spec);

        $this->assertCount(1, $out);
        $this->assertSame('xss', $out[0]['category']);
    }

    public function test_filters_are_and_combined(): void
    {
        $spec = FindingFilter::fromOptions(['owasp' => 'A03', 'severity' => 'critical']);
        $out  = FindingFilter::apply($this->findings, $spec);

        $this->assertCount(1, $out);
        $this->assertSame('sql_injection', $out[0]['category']);
    }

    public function test_apply_to_result_recomputes_summary(): void
    {
        $result = [
            'all_findings'  => $this->findings,
            'agent_results' => [
                'security' => ['findings' => [$this->findings[0], $this->findings[3]]],
            ],
            'summary' => [
                'total_issues' => 4,
                'critical' => 1, 'high' => 1, 'medium' => 1, 'low' => 1,
                'by_severity' => ['critical' => 1, 'high' => 1, 'medium' => 1, 'low' => 1],
            ],
        ];

        $spec = FindingFilter::fromOptions(['min-severity' => 'high']);
        $out  = FindingFilter::applyToResult($result, $spec);

        $this->assertCount(2, $out['all_findings']);
        $this->assertSame(2, $out['summary']['total_issues']);
        $this->assertSame(1, $out['summary']['critical']);
        $this->assertSame(1, $out['summary']['high']);
        $this->assertSame(0, $out['summary']['medium']);
        $this->assertSame(0, $out['summary']['low']);
        $this->assertSame(1, $out['summary']['by_severity']['high']);
        // agent findings filtered too (xss is medium → removed)
        $this->assertCount(1, $out['agent_results']['security']['findings']);
    }
}
