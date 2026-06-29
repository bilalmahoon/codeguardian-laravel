<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Quality gates / budgets. Turns a CodeGuardian result into a pass/fail
 * decision against thresholds defined in config, so CI can block a merge when
 * code health regresses past an agreed budget.
 *
 * Pure and fully unit-testable — no IO, no container access required (config
 * reads are guarded).
 */
final class QualityGate
{
    /**
     * Supported budget keys and whether the actual value must stay BELOW
     * (max_*) or ABOVE (min_*) the configured threshold.
     */
    public const KEYS = [
        'max_critical', 'max_high', 'max_medium', 'max_low', 'max_total',
        'min_score', 'max_risk', 'min_quality',
    ];

    /** @return array<string,int> */
    public static function fromConfig(): array
    {
        try {
            $gates = config('codeguardian.gates', []);
        } catch (\Throwable) {
            $gates = [];
        }

        return self::normalize(is_array($gates) ? $gates : []);
    }

    /**
     * Keep only known, integer-valued gate keys.
     *
     * @param  array<string,mixed> $gates
     * @return array<string,int>
     */
    public static function normalize(array $gates): array
    {
        $out = [];
        foreach ($gates as $key => $value) {
            if (in_array($key, self::KEYS, true) && is_numeric($value)) {
                $out[$key] = (int) $value;
            }
        }
        return $out;
    }

    /** @param array<string,int> $gates */
    public static function isEmpty(array $gates): bool
    {
        return $gates === [];
    }

    /**
     * Evaluate a results array against the gates.
     *
     * @param  array<string,mixed> $results
     * @param  array<string,int>   $gates
     * @return array{passed:bool, violations: list<array{gate:string, limit:int, actual:int, message:string}>}
     */
    public static function evaluate(array $results, array $gates): array
    {
        $summary = $results['summary'] ?? [];

        $bySeverity = $summary['by_severity'] ?? $summary;
        $actual = [
            'critical' => (int) ($bySeverity['critical'] ?? 0),
            'high'     => (int) ($bySeverity['high'] ?? 0),
            'medium'   => (int) ($bySeverity['medium'] ?? 0),
            'low'      => (int) ($bySeverity['low'] ?? 0),
            'total'    => (int) ($summary['total_issues'] ?? 0),
            'score'    => (int) ($results['overall_score'] ?? 100),
            'risk'     => (int) ($summary['risk_score'] ?? 0),
            'quality'  => (int) ($results['quality']['overall'] ?? 100),
        ];

        $violations = [];

        $checkMax = function (string $gate, int $value) use ($gates, &$violations): void {
            if (! isset($gates[$gate])) {
                return;
            }
            $limit = $gates[$gate];
            if ($value > $limit) {
                $violations[] = [
                    'gate'    => $gate,
                    'limit'   => $limit,
                    'actual'  => $value,
                    'message' => sprintf('%s exceeded: %d > %d', $gate, $value, $limit),
                ];
            }
        };

        $checkMin = function (string $gate, int $value) use ($gates, &$violations): void {
            if (! isset($gates[$gate])) {
                return;
            }
            $limit = $gates[$gate];
            if ($value < $limit) {
                $violations[] = [
                    'gate'    => $gate,
                    'limit'   => $limit,
                    'actual'  => $value,
                    'message' => sprintf('%s not met: %d < %d', $gate, $value, $limit),
                ];
            }
        };

        $checkMax('max_critical', $actual['critical']);
        $checkMax('max_high',     $actual['high']);
        $checkMax('max_medium',   $actual['medium']);
        $checkMax('max_low',      $actual['low']);
        $checkMax('max_total',    $actual['total']);
        $checkMax('max_risk',     $actual['risk']);
        $checkMin('min_score',    $actual['score']);
        $checkMin('min_quality',  $actual['quality']);

        return [
            'passed'     => $violations === [],
            'violations' => $violations,
        ];
    }
}
