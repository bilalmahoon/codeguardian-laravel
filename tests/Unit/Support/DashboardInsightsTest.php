<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\DashboardInsights;
use PHPUnit\Framework\TestCase;

class DashboardInsightsTest extends TestCase
{
    public function test_from_history_builds_points_and_direction_up(): void
    {
        $out = DashboardInsights::fromHistory([
            ['at' => '2026-01-01T00:00:00+00:00', 'score' => 60, 'risk' => 50, 'total' => 30],
            ['at' => '2026-02-01T00:00:00+00:00', 'score' => 75, 'risk' => 30, 'total' => 18],
        ]);

        $this->assertCount(2, $out['points']);
        $this->assertSame(60, $out['points'][0]['score']);
        $this->assertSame('up', $out['direction']);
        $this->assertSame(15, $out['delta']);
        $this->assertSame(75, $out['latest']['score']);
    }

    public function test_from_history_empty_is_flat(): void
    {
        $out = DashboardInsights::fromHistory([]);

        $this->assertSame([], $out['points']);
        $this->assertNull($out['latest']);
        $this->assertSame('flat', $out['direction']);
        $this->assertSame(0, $out['delta']);
    }

    public function test_category_breakdown_counts_and_worst_severity(): void
    {
        $findings = [
            ['category' => 'security', 'severity' => 'low'],
            ['category' => 'security', 'severity' => 'critical'],
            ['category' => 'performance', 'severity' => 'high'],
        ];

        $out = DashboardInsights::categoryBreakdown($findings);

        $this->assertSame('security', $out[0]['category']);
        $this->assertSame(2, $out[0]['count']);
        $this->assertSame('critical', $out[0]['severity']);
        $this->assertSame('performance', $out[1]['category']);
        $this->assertSame('high', $out[1]['severity']);
    }

    public function test_severity_breakdown_totals(): void
    {
        $out = DashboardInsights::severityBreakdown([
            ['severity' => 'critical'],
            ['severity' => 'high'],
            ['severity' => 'high'],
            ['severity' => 'low'],
        ]);

        $this->assertSame(1, $out['critical']);
        $this->assertSame(2, $out['high']);
        $this->assertSame(0, $out['medium']);
        $this->assertSame(1, $out['low']);
        $this->assertSame(4, $out['total']);
    }

    public function test_sparkline_renders_svg_with_polyline(): void
    {
        $svg = DashboardInsights::sparkline([10, 50, 90]);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<polyline', $svg);
        $this->assertStringContainsString('<polygon', $svg);
    }

    public function test_sparkline_empty_is_blank(): void
    {
        $this->assertSame('', DashboardInsights::sparkline([]));
    }

    public function test_sparkline_single_value_does_not_divide_by_zero(): void
    {
        $svg = DashboardInsights::sparkline([42]);
        $this->assertStringContainsString('<polyline', $svg);
    }
}
