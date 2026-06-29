<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\RiskScorer;
use PHPUnit\Framework\TestCase;

class RiskScorerTest extends TestCase
{
    public function test_no_findings_is_minimal_risk(): void
    {
        $r = RiskScorer::assess([]);
        $this->assertSame(0, $r['risk_score']);
        $this->assertSame('minimal', $r['risk_level']);
        $this->assertNotEmpty($r['reasoning']);
    }

    public function test_critical_finding_yields_critical_level(): void
    {
        $r = RiskScorer::assess([
            ['category' => 'sql_injection', 'severity' => 'critical', 'confidence' => 'high'],
        ]);

        $this->assertSame('critical', $r['risk_level']);
        $this->assertGreaterThan(0, $r['risk_score']);
    }

    public function test_low_confidence_reduces_score(): void
    {
        $high = RiskScorer::assess([['category' => 'x', 'severity' => 'high', 'confidence' => 'high']]);
        $low  = RiskScorer::assess([['category' => 'x', 'severity' => 'high', 'confidence' => 'low']]);

        $this->assertGreaterThan($low['risk_score'], $high['risk_score']);
    }

    public function test_score_is_capped_at_100(): void
    {
        $many = array_fill(0, 50, ['category' => 'sql_injection', 'severity' => 'critical', 'confidence' => 'high']);
        $r    = RiskScorer::assess($many);

        $this->assertLessThanOrEqual(100, $r['risk_score']);
        $this->assertSame(100, $r['risk_score']);
    }

    public function test_reasoning_mentions_top_category(): void
    {
        $r = RiskScorer::assess([
            ['category' => 'n_plus_one', 'severity' => 'high', 'confidence' => 'medium'],
            ['category' => 'n_plus_one', 'severity' => 'high', 'confidence' => 'medium'],
        ]);

        $joined = implode(' ', $r['reasoning']);
        $this->assertStringContainsString('n plus one', $joined);
    }
}
