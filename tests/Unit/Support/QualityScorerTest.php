<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\QualityScorer;
use PHPUnit\Framework\TestCase;

class QualityScorerTest extends TestCase
{
    public function test_clean_codebase_scores_perfectly(): void
    {
        $result = QualityScorer::assess([], []);

        $this->assertSame(100, $result['overall']);
        $this->assertSame('A', $result['grade']);
        foreach (QualityScorer::DIMENSIONS as $dim) {
            $this->assertSame(100, $result['dimensions'][$dim]['score']);
            $this->assertStringContainsString('no issues', $result['dimensions'][$dim]['reasoning']);
        }
    }

    public function test_all_six_dimensions_present(): void
    {
        $result = QualityScorer::assess([], []);
        $this->assertSame(
            ['architecture', 'security', 'performance', 'maintainability', 'testability', 'reliability'],
            array_keys($result['dimensions'])
        );
    }

    public function test_anchor_scores_drive_primary_dimensions(): void
    {
        $result = QualityScorer::assess([], [
            'architecture_score' => 72,
            'security_score'     => 40,
            'performance_score'  => 88,
            'tech_debt_score'    => 55,
        ]);

        $this->assertSame(72, $result['dimensions']['architecture']['score']);
        $this->assertSame(40, $result['dimensions']['security']['score']);
        $this->assertSame(88, $result['dimensions']['performance']['score']);
        $this->assertSame(55, $result['dimensions']['maintainability']['score']);
        $this->assertSame('C', $result['dimensions']['architecture']['grade']);
        $this->assertSame('F', $result['dimensions']['security']['grade']);
    }

    public function test_security_findings_counted_in_security_dimension(): void
    {
        $findings = [
            ['severity' => 'critical', 'category' => 'sql_injection'],
            ['severity' => 'high', 'category' => 'xss'],
        ];

        $result = QualityScorer::assess($findings);
        $sec    = $result['dimensions']['security'];

        $this->assertSame(2, $sec['issues']);
        $this->assertSame(1, $sec['critical']);
        $this->assertSame(1, $sec['high']);
        // 100 - 26 (critical) - 13 (high) = 61
        $this->assertSame(61, $sec['score']);
    }

    public function test_testability_derived_from_complexity(): void
    {
        $findings = [
            ['severity' => 'high', 'category' => 'high_complexity'],
            ['severity' => 'high', 'category' => 'god_class'],
        ];

        $result = QualityScorer::assess($findings);
        $this->assertSame(2, $result['dimensions']['testability']['issues']);
        $this->assertLessThan(100, $result['dimensions']['testability']['score']);
    }

    public function test_reliability_derived_from_empty_catch(): void
    {
        $findings = [
            ['severity' => 'medium', 'category' => 'empty_catch'],
            ['severity' => 'high', 'category' => 'debug_mode'],
        ];

        $result = QualityScorer::assess($findings);
        $this->assertSame(2, $result['dimensions']['reliability']['issues']);
        $this->assertLessThan(100, $result['dimensions']['reliability']['score']);
    }

    public function test_unknown_category_falls_into_maintainability(): void
    {
        $findings = [['severity' => 'medium', 'category' => 'some_new_smell']];
        $result   = QualityScorer::assess($findings);

        $this->assertSame(1, $result['dimensions']['maintainability']['issues']);
    }

    public function test_score_clamped_to_zero(): void
    {
        $findings = array_fill(0, 20, ['severity' => 'critical', 'category' => 'sql_injection']);
        $result   = QualityScorer::assess($findings);

        $this->assertSame(0, $result['dimensions']['security']['score']);
        $this->assertGreaterThanOrEqual(0, $result['overall']);
    }
}
