<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use CodeGuardian\Laravel\Analyzers\Severity;

/**
 * Derives an explainable risk assessment from a flat list of findings.
 *
 * Unlike the per-analyzer quality scores (which answer "how good is this code"),
 * the risk score answers "how urgently does this need attention", weighted by
 * severity and confidence, and always paired with human-readable reasoning so a
 * reviewer understands *why* the number is what it is (Phase 6).
 */
class RiskScorer
{
    private const SEVERITY_WEIGHT = [
        Severity::CRITICAL => 25,
        Severity::HIGH     => 10,
        Severity::MEDIUM   => 4,
        Severity::LOW      => 1,
    ];

    private const CONFIDENCE_FACTOR = [
        'high'   => 1.0,
        'medium' => 0.75,
        'low'    => 0.5,
    ];

    /**
     * @param  array<int,array<string,mixed>> $findings
     * @return array{risk_score:int,risk_level:string,reasoning:array<int,string>}
     */
    public static function assess(array $findings): array
    {
        if (empty($findings)) {
            return [
                'risk_score' => 0,
                'risk_level' => 'minimal',
                'reasoning'  => ['No findings — nothing to remediate.'],
            ];
        }

        $bySeverity = [Severity::CRITICAL => 0, Severity::HIGH => 0, Severity::MEDIUM => 0, Severity::LOW => 0];
        $byCategory = [];
        $raw        = 0.0;

        foreach ($findings as $f) {
            $sev  = Severity::clamp($f['severity'] ?? '');
            $conf = strtolower((string) ($f['confidence'] ?? 'medium'));
            $bySeverity[$sev]++;

            $cat = (string) ($f['category'] ?? 'unknown');
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;

            $raw += (self::SEVERITY_WEIGHT[$sev] ?? 1) * (self::CONFIDENCE_FACTOR[$conf] ?? 0.75);
        }

        $score = (int) min(100, round($raw));
        $level = match (true) {
            $bySeverity[Severity::CRITICAL] > 0 || $score >= 75 => 'critical',
            $score >= 45                                        => 'high',
            $score >= 20                                        => 'medium',
            $score > 0                                          => 'low',
            default                                            => 'minimal',
        };

        arsort($byCategory);

        return [
            'risk_score' => $score,
            'risk_level' => $level,
            'reasoning'  => self::buildReasoning($bySeverity, $byCategory, count($findings)),
        ];
    }

    /**
     * @param  array<string,int> $bySeverity
     * @param  array<string,int> $byCategory
     * @return array<int,string>
     */
    private static function buildReasoning(array $bySeverity, array $byCategory, int $total): array
    {
        $reasoning = [];

        if ($bySeverity[Severity::CRITICAL] > 0) {
            $reasoning[] = "{$bySeverity[Severity::CRITICAL]} critical finding(s) drive the risk to its ceiling — these should block release.";
        } elseif ($bySeverity[Severity::HIGH] > 0) {
            $reasoning[] = "{$bySeverity[Severity::HIGH]} high-severity finding(s) are the main risk driver; no criticals present.";
        } else {
            $reasoning[] = "No critical/high findings — remaining risk is from medium/low maintainability issues.";
        }

        $top = array_slice($byCategory, 0, 3, true);
        if (! empty($top)) {
            $parts = [];
            foreach ($top as $cat => $count) {
                $parts[] = str_replace('_', ' ', $cat) . " ({$count})";
            }
            $reasoning[] = 'Concentrated in: ' . implode(', ', $parts) . '.';
        }

        $reasoning[] = "Weighted across {$total} finding(s) by severity × confidence.";

        return $reasoning;
    }
}
