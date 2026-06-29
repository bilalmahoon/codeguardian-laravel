<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use CodeGuardian\Laravel\Support\QualityScorer;
use CodeGuardian\Laravel\Support\ReportFormatter;
use Orchestra\Testbench\TestCase;

/**
 * Verifies the enterprise HTML report renders the executive summary and the
 * six-dimension quality scorecard. Booted in Testbench because ReportFormatter
 * relies on the File facade and the e()/now() helpers.
 */
class EnterpriseReportTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\CodeGuardian\Laravel\CodeGuardianServiceProvider::class];
    }

    /** @test */
    public function test_html_report_includes_executive_summary_and_dimensions(): void
    {
        $findings = [
            ['severity' => 'critical', 'category' => 'sql_injection', 'title' => 'SQLi', 'file' => 'app/X.php', 'owasp' => 'A03:2021-Injection', 'cwe' => 'CWE-89'],
            ['severity' => 'high', 'category' => 'high_complexity', 'title' => 'Complex', 'file' => 'app/Y.php'],
        ];

        $results = [
            'project_name'  => 'demo',
            'project_type'  => 'laravel',
            'overall_score' => 58,
            'grade'         => 'F',
            'files_scanned' => 3,
            'total_lines'   => 400,
            'scores'        => ['security_score' => 40, 'tech_debt_score' => 60],
            'agent_results' => [
                'security' => ['findings' => [$findings[0]]],
                'tech_debt' => ['findings' => [$findings[1]]],
            ],
            'all_findings'  => $findings,
            'summary'       => [
                'total_files'  => 3,
                'total_lines'  => 400,
                'total_issues' => 2,
                'critical'     => 1,
                'high'         => 1,
                'medium'       => 0,
                'low'          => 0,
                'risk_score'   => 55,
                'risk_level'   => 'high',
            ],
            'quality'       => QualityScorer::assess($findings, ['security_score' => 40, 'tech_debt_score' => 60]),
        ];

        $dir   = sys_get_temp_dir() . '/cg-report-' . uniqid();
        $paths = (new ReportFormatter())->save($results, $dir, 'html');

        $this->assertNotEmpty($paths);
        $html = file_get_contents($paths[0]);

        $this->assertStringContainsString('Executive Summary', $html);
        $this->assertStringContainsString('Quality Dimensions', $html);
        $this->assertStringContainsString('Testability', $html);
        $this->assertStringContainsString('Reliability', $html);
        $this->assertStringContainsString('Risk ·', $html);

        // cleanup
        foreach ($paths as $p) {
            @unlink($p);
        }
        @rmdir($dir);
    }

    /** @test */
    public function test_markdown_report_renders_dimensions_and_findings(): void
    {
        $findings = [
            ['severity' => 'high', 'category' => 'n_plus_one', 'title' => 'N+1 query', 'file' => 'app/Z.php'],
        ];

        $results = [
            'project_name'  => 'demo',
            'overall_score' => 70,
            'grade'         => 'C',
            'all_findings'  => $findings,
            'summary'       => [
                'total_issues' => 1, 'critical' => 0, 'high' => 1, 'medium' => 0, 'low' => 0,
                'risk_score'   => 20, 'risk_level' => 'low',
                'top_findings' => [['severity' => 'high', 'title' => 'N+1 query', 'file' => 'app/Z.php']],
            ],
            'quality'       => QualityScorer::assess($findings, ['performance_score' => 60]),
        ];

        $md = (new ReportFormatter())->renderMarkdown($results);

        $this->assertStringContainsString('# CodeGuardian AI Report', $md);
        $this->assertStringContainsString('## Executive Summary', $md);
        $this->assertStringContainsString('## Quality Dimensions', $md);
        $this->assertStringContainsString('| Dimension | Score | Grade | Notes |', $md);
        $this->assertStringContainsString('N+1 query', $md);
        $this->assertStringContainsString('Risk:', $md);
    }

    /** @test */
    public function test_html_report_embeds_trend_sparkline(): void
    {
        $results = [
            'project_name'  => 'demo',
            'overall_score' => 80,
            'grade'         => 'B',
            'summary'       => ['total_issues' => 3, 'critical' => 0, 'high' => 1, 'medium' => 1, 'low' => 1],
            'history'       => [
                ['score' => 60], ['score' => 70], ['score' => 80],
            ],
        ];

        $dir   = sys_get_temp_dir() . '/cg-html-' . uniqid();
        $paths = (new ReportFormatter())->save($results, $dir, 'html');
        $html  = file_get_contents($paths[0]);

        $this->assertStringContainsString('Quality trend', $html);
        $this->assertStringContainsString('<polyline', $html);

        foreach ($paths as $p) {
            @unlink($p);
        }
        @rmdir($dir);
    }

    /** @test */
    public function test_html_report_omits_trend_with_insufficient_history(): void
    {
        $results = [
            'project_name'  => 'demo',
            'overall_score' => 80,
            'grade'         => 'B',
            'summary'       => ['total_issues' => 0, 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0],
            'history'       => [['score' => 80]],
        ];

        $dir   = sys_get_temp_dir() . '/cg-html2-' . uniqid();
        $paths = (new ReportFormatter())->save($results, $dir, 'html');
        $html  = file_get_contents($paths[0]);

        $this->assertStringNotContainsString('Quality trend', $html);

        foreach ($paths as $p) {
            @unlink($p);
        }
        @rmdir($dir);
    }

    /** @test */
    public function test_save_writes_sarif_file(): void
    {
        $results = [
            'project_name' => 'demo',
            'all_findings' => [[
                'category' => 'sql_injection', 'severity' => 'critical', 'title' => 'SQLi',
                'file' => 'app/X.php', 'line_start' => 9, 'cwe' => 'CWE-89',
            ]],
            'summary' => ['total_issues' => 1, 'critical' => 1, 'high' => 0, 'medium' => 0, 'low' => 0],
        ];

        $dir   = sys_get_temp_dir() . '/cg-sarif-' . uniqid();
        $paths = (new ReportFormatter())->save($results, $dir, 'sarif');

        $this->assertNotEmpty($paths);
        $this->assertStringEndsWith('.sarif', $paths[0]);
        $doc = json_decode(file_get_contents($paths[0]), true);
        $this->assertSame('2.1.0', $doc['version']);
        $this->assertSame('sql_injection', $doc['runs'][0]['results'][0]['ruleId']);

        foreach ($paths as $p) {
            @unlink($p);
        }
        @rmdir($dir);
    }
}
