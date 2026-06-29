<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use CodeGuardian\Laravel\Analyzers\Severity;

/**
 * Pure aggregation + tiny SVG charting for the dashboard "Insights" page and the
 * per-run findings explorer. No IO, no container — easy to unit-test. The
 * dashboard controller feeds it history records (from HistoryStore) and the
 * findings of a run's JSON report.
 */
final class DashboardInsights
{
    /**
     * Turn history records into a trend series + headline + direction.
     *
     * @param  array<int,array<string,mixed>> $history  oldest → newest
     * @return array{
     *   points: list<array{label:string,score:int,risk:int,total:int}>,
     *   latest: array<string,mixed>|null,
     *   direction: string,
     *   delta: int
     * }
     */
    public static function fromHistory(array $history): array
    {
        $points = [];
        foreach ($history as $rec) {
            $points[] = [
                'label' => self::shortDate((string) ($rec['at'] ?? '')),
                'score' => (int) ($rec['score'] ?? 0),
                'risk'  => (int) ($rec['risk'] ?? 0),
                'total' => (int) ($rec['total'] ?? 0),
            ];
        }

        $latest = $history === [] ? null : $history[count($history) - 1];

        $direction = 'flat';
        $delta     = 0;
        if (count($points) >= 2) {
            $first = $points[0]['score'];
            $last  = $points[count($points) - 1]['score'];
            $delta = $last - $first;
            $direction = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
        }

        return [
            'points'    => $points,
            'latest'    => $latest,
            'direction' => $direction,
            'delta'     => $delta,
        ];
    }

    /**
     * Group findings by category with counts + the worst severity seen.
     *
     * @param  array<int,array<string,mixed>> $findings
     * @return list<array{category:string,count:int,severity:string}>
     */
    public static function categoryBreakdown(array $findings, int $limit = 12): array
    {
        $counts   = [];
        $worstSev = [];

        foreach ($findings as $f) {
            $cat = (string) ($f['category'] ?? 'unknown');
            $sev = Severity::clamp((string) ($f['severity'] ?? 'low'));
            $counts[$cat] = ($counts[$cat] ?? 0) + 1;

            $rank    = Severity::ORDER[$sev] ?? 4;
            $current = $worstSev[$cat] ?? null;
            if ($current === null || $rank < (Severity::ORDER[$current] ?? 4)) {
                $worstSev[$cat] = $sev;
            }
        }

        arsort($counts);

        $out = [];
        foreach ($counts as $cat => $count) {
            $out[] = [
                'category' => $cat,
                'count'    => $count,
                'severity' => $worstSev[$cat] ?? 'low',
            ];
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<int,array<string,mixed>> $findings
     * @return array{critical:int,high:int,medium:int,low:int,total:int}
     */
    public static function severityBreakdown(array $findings): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($findings as $f) {
            $counts[Severity::clamp((string) ($f['severity'] ?? 'low'))]++;
        }
        $counts['total'] = array_sum($counts);
        return $counts;
    }

    /**
     * Build an inline SVG polyline sparkline for a numeric series (0–100).
     *
     * @param list<int> $values
     */
    public static function sparkline(array $values, int $width = 600, int $height = 120, string $color = '#5b8cff'): string
    {
        $n = count($values);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            $values = [$values[0], $values[0]];
            $n = 2;
        }

        $pad     = 6;
        $w       = $width - 2 * $pad;
        $h       = $height - 2 * $pad;
        $stepX   = $w / ($n - 1);

        $coords = [];
        foreach ($values as $i => $v) {
            $v = max(0, min(100, (int) $v));
            $x = $pad + $i * $stepX;
            $y = $pad + $h - ($v / 100) * $h;
            $coords[] = round($x, 1) . ',' . round($y, 1);
        }

        $points = implode(' ', $coords);
        $area    = "{$pad},{$height} {$points} " . ($pad + $w) . ",{$height}";

        return sprintf(
            '<svg viewBox="0 0 %d %d" preserveAspectRatio="none" width="100%%" height="%d" role="img">'
            . '<polygon points="%s" fill="%s" fill-opacity="0.12" />'
            . '<polyline points="%s" fill="none" stroke="%s" stroke-width="2" />'
            . '</svg>',
            $width, $height, $height, $area, $color, $points, $color
        );
    }

    private static function shortDate(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts ? date('M j', $ts) : '';
    }
}
