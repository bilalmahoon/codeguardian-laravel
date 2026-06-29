<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\PrComment;
use PHPUnit\Framework\TestCase;

class PrCommentTest extends TestCase
{
    private function results(): array
    {
        return [
            'project_name'  => 'acme/api',
            'overall_score' => 72,
            'grade'         => 'B',
            'summary'       => [
                'total_issues' => 4,
                'critical'     => 1,
                'high'         => 1,
                'medium'       => 1,
                'low'          => 1,
                'risk_score'   => 41,
                'risk_level'   => 'medium',
            ],
            'all_findings' => [
                ['severity' => 'low', 'title' => 'Missing types', 'file' => 'app/A.php', 'line_start' => 3],
                ['severity' => 'critical', 'title' => 'SQL injection', 'file' => 'app/B.php', 'line_start' => 10],
                ['severity' => 'high', 'title' => 'N+1 query', 'file' => 'app/C.php'],
            ],
        ];
    }

    public function test_body_contains_marker_for_idempotent_updates(): void
    {
        $this->assertStringContainsString(PrComment::MARKER, PrComment::body($this->results()));
    }

    public function test_body_includes_scores_and_severity_table(): void
    {
        $body = PrComment::body($this->results());

        $this->assertStringContainsString('acme/api', $body);
        $this->assertStringContainsString('72/100', $body);
        $this->assertStringContainsString('Grade B', $body);
        $this->assertStringContainsString('41/100 (MEDIUM)', $body);
        $this->assertStringContainsString('| 🔴 Critical | 1 |', $body);
        $this->assertStringContainsString('| **Total** | **4** |', $body);
    }

    public function test_findings_sorted_by_severity_critical_first(): void
    {
        $body = PrComment::body($this->results());

        $critPos = strpos($body, 'SQL injection');
        $lowPos  = strpos($body, 'Missing types');

        $this->assertNotFalse($critPos);
        $this->assertNotFalse($lowPos);
        $this->assertLessThan($lowPos, $critPos);
    }

    public function test_max_findings_cap_is_respected(): void
    {
        $body = PrComment::body($this->results(), 1);

        $this->assertStringContainsString('SQL injection', $body);
        $this->assertStringNotContainsString('Missing types', $body);
    }

    public function test_pipes_in_titles_are_escaped(): void
    {
        $r = $this->results();
        $r['all_findings'] = [['severity' => 'high', 'title' => 'a | b', 'file' => 'x.php']];

        $body = PrComment::body($r);
        $this->assertStringContainsString('a \\| b', $body);
    }

    public function test_baseline_diff_rendered_when_present(): void
    {
        $r = $this->results();
        $r['summary']['baseline'] = ['new' => 2, 'existing' => 5, 'fixed' => 3];

        $body = PrComment::body($r);
        $this->assertStringContainsString('🆕 2 new', $body);
        $this->assertStringContainsString('✅ 3 fixed', $body);
    }

    public function test_clean_project_shows_green(): void
    {
        $body = PrComment::body([
            'project_name' => 'clean',
            'summary'      => ['total_issues' => 0],
        ]);
        $this->assertStringContainsString('🟢', $body);
    }
}
