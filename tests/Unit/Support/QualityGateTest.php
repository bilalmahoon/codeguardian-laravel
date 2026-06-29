<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\QualityGate;
use PHPUnit\Framework\TestCase;

class QualityGateTest extends TestCase
{
    private function results(array $overrides = []): array
    {
        return array_merge([
            'overall_score' => 80,
            'summary' => [
                'total_issues' => 10,
                'critical'     => 0,
                'high'         => 2,
                'medium'       => 5,
                'low'          => 3,
                'risk_score'   => 30,
            ],
            'quality' => ['overall' => 75],
        ], $overrides);
    }

    public function test_normalize_keeps_only_known_integer_keys(): void
    {
        $gates = QualityGate::normalize([
            'max_critical' => '0',
            'min_score'    => 70,
            'bogus'        => 5,
            'max_high'     => 'not-a-number',
        ]);

        $this->assertSame(['max_critical' => 0, 'min_score' => 70], $gates);
    }

    public function test_passes_when_within_budget(): void
    {
        $verdict = QualityGate::evaluate($this->results(), [
            'max_critical' => 0,
            'max_high'     => 5,
            'min_score'    => 70,
        ]);

        $this->assertTrue($verdict['passed']);
        $this->assertSame([], $verdict['violations']);
    }

    public function test_fails_on_max_violation(): void
    {
        $verdict = QualityGate::evaluate($this->results(), ['max_high' => 1]);

        $this->assertFalse($verdict['passed']);
        $this->assertCount(1, $verdict['violations']);
        $this->assertSame('max_high', $verdict['violations'][0]['gate']);
        $this->assertSame(2, $verdict['violations'][0]['actual']);
    }

    public function test_fails_on_min_violation(): void
    {
        $verdict = QualityGate::evaluate($this->results(['overall_score' => 50]), ['min_score' => 70]);

        $this->assertFalse($verdict['passed']);
        $this->assertSame('min_score', $verdict['violations'][0]['gate']);
    }

    public function test_reads_by_severity_nested_summary(): void
    {
        $results = [
            'overall_score' => 90,
            'summary' => ['by_severity' => ['critical' => 3, 'high' => 0, 'medium' => 0, 'low' => 0], 'total_issues' => 3],
        ];

        $verdict = QualityGate::evaluate($results, ['max_critical' => 0]);
        $this->assertFalse($verdict['passed']);
        $this->assertSame(3, $verdict['violations'][0]['actual']);
    }
}
