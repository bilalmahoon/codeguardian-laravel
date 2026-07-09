<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use CodeGuardian\Laravel\Analyzers\Severity;

/**
 * Computes an enterprise-grade, multi-dimensional quality assessment from a set
 * of findings (and, when available, the analyzers' own scores).
 *
 * Six dimensions are reported, each with a 0–100 score, a letter grade, the
 * count of contributing issues, and plain-English reasoning:
 *
 *   - Architecture     — structure, layering, SOLID, coupling
 *   - Security         — vulnerabilities (OWASP/CWE mapped)
 *   - Performance      — query/CPU efficiency, scalability
 *   - Maintainability  — complexity, duplication, code smells, readability
 *   - Testability      — how hard the code is to unit-test
 *   - Reliability      — error handling, fail-safes, operational soundness
 *
 * The first four anchor to the analyzers' scores when provided (so the report
 * is internally consistent); Testability and Reliability are derived from the
 * categories of findings. Everything is deterministic and explainable.
 */
final class QualityScorer
{
    public const DIMENSIONS = [
        'architecture', 'security', 'performance',
        'maintainability', 'testability', 'reliability',
    ];

    /** Per-finding penalty by severity (points subtracted from 100). */
    private const PENALTY = [
        Severity::CRITICAL => 26,
        Severity::HIGH     => 13,
        Severity::MEDIUM   => 5,
        Severity::LOW      => 2,
    ];

    /**
     * Penalty-per-file at which a size-normalised dimension scores 50/100. Tuned
     * so a codebase averaging ~one medium-severity issue per file lands mid-band
     * instead of collapsing to 0 (which is what the old absolute model did on any
     * large scan — every derived dimension became 0 regardless of real health).
     */
    private const DENSITY_MIDPOINT = 4.0;

    /**
     * Categories that make code hard to unit-test (high coupling, branching,
     * size). Used to derive the Testability dimension.
     */
    private const TESTABILITY_CATEGORIES = [
        'high_complexity', 'large_class', 'god_class', 'long_method',
        'long_parameter_list', 'boolean_flag_parameter', 'deep_nesting',
        'service_layer', 'fat_controller', 'fat_model', 'business_logic_in_model',
        'dependency_injection', 'duplication',
    ];

    /**
     * Categories that signal operational fragility (swallowed errors, debug
     * leftovers, disabled safety nets). Used to derive the Reliability dimension.
     */
    private const RELIABILITY_CATEGORIES = [
        'empty_catch', 'debug_code', 'debug_mode', 'tls_verification',
        'csrf_disabled', 'disabled_csrf', 'insecure_deserialization',
        'dynamic_include', 'ssrf', 'error_handling', 'n_plus_one',
        'query_in_loop',
    ];

    /**
     * @param array<int,array<string,mixed>> $findings    normalised findings (severity, category)
     * @param array<string,int>              $agentScores  e.g. ['architecture_score'=>72, ...]
     * @param int                            $totalFiles   files scanned; when > 0, dimensions WITHOUT
     *                                                     an analyzer anchor (testability, reliability)
     *                                                     are scored by density instead of raw count.
     * @return array{dimensions: array<string,array<string,mixed>>, overall: int, grade: string}
     */
    public static function assess(array $findings, array $agentScores = [], int $totalFiles = 0): array
    {
        $byCategory = self::bucketByCategory($findings);

        $dimensions = [];

        $dimensions['architecture'] = self::dimension(
            'Architecture',
            $agentScores['architecture_score'] ?? null,
            $byCategory['architecture'] ?? [],
            'Structure, layering and SOLID adherence',
            $totalFiles
        );
        $dimensions['security'] = self::dimension(
            'Security',
            $agentScores['security_score'] ?? null,
            $byCategory['security'] ?? [],
            'Resistance to the OWASP Top 10 and known weaknesses',
            $totalFiles
        );
        $dimensions['performance'] = self::dimension(
            'Performance',
            $agentScores['performance_score'] ?? null,
            $byCategory['performance'] ?? [],
            'Query efficiency, CPU cost and scalability',
            $totalFiles
        );
        $dimensions['maintainability'] = self::dimension(
            'Maintainability',
            $agentScores['tech_debt_score'] ?? null,
            $byCategory['maintainability'] ?? [],
            'Complexity, duplication and readability',
            $totalFiles
        );
        $dimensions['testability'] = self::dimension(
            'Testability',
            null,
            self::collect($findings, self::TESTABILITY_CATEGORIES),
            'How easily the code can be unit-tested',
            $totalFiles
        );
        $dimensions['reliability'] = self::dimension(
            'Reliability',
            null,
            self::collect($findings, self::RELIABILITY_CATEGORIES),
            'Error handling and operational fail-safes',
            $totalFiles
        );

        $overall = (int) round(
            array_sum(array_map(fn($d) => $d['score'], $dimensions)) / count($dimensions)
        );

        return [
            'dimensions' => $dimensions,
            'overall'    => $overall,
            'grade'      => self::grade($overall),
        ];
    }

    /**
     * Build one dimension. When an anchor score is supplied (from an analyzer)
     * it is used as the headline; otherwise the score is derived from penalties.
     *
     * @param array<int,array<string,mixed>> $findings
     */
    private static function dimension(string $label, ?int $anchor, array $findings, string $blurb, int $totalFiles = 0): array
    {
        $derived = self::scoreFromFindings($findings, $totalFiles);
        $score   = $anchor !== null ? max(0, min(100, $anchor)) : $derived;

        $counts = self::severityCounts($findings);

        return [
            'label'     => $label,
            'score'     => $score,
            'grade'     => self::grade($score),
            'issues'    => count($findings),
            'critical'  => $counts[Severity::CRITICAL],
            'high'      => $counts[Severity::HIGH],
            'medium'    => $counts[Severity::MEDIUM],
            'low'       => $counts[Severity::LOW],
            'reasoning' => self::reasoning($label, $blurb, $score, $counts),
        ];
    }

    /**
     * Score a dimension from its findings.
     *
     * With a known codebase size ($totalFiles > 0) we score by DENSITY — penalty
     * per file on a saturating curve — so a big codebase with proportionally few
     * issues gets a fair score instead of always flooring at 0. Without a size
     * (small/unknown scope) we keep the legacy absolute model.
     *
     * @param array<int,array<string,mixed>> $findings
     */
    private static function scoreFromFindings(array $findings, int $totalFiles = 0): int
    {
        $penalty = 0;
        foreach ($findings as $f) {
            $sev      = Severity::clamp($f['severity'] ?? '');
            $penalty += self::PENALTY[$sev] ?? 0;
        }

        if ($totalFiles <= 0) {
            return max(0, min(100, 100 - $penalty));
        }

        $perFile = $penalty / $totalFiles;
        $score   = 100 * self::DENSITY_MIDPOINT / (self::DENSITY_MIDPOINT + $perFile);

        return (int) round(max(0, min(100, $score)));
    }

    /**
     * Group findings into the four primary dimension buckets by category.
     *
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,array<int,array<string,mixed>>>
     */
    private static function bucketByCategory(array $findings): array
    {
        $buckets = ['architecture' => [], 'security' => [], 'performance' => [], 'maintainability' => []];

        foreach ($findings as $f) {
            $buckets[self::primaryDimension((string) ($f['category'] ?? ''))][] = $f;
        }

        return $buckets;
    }

    /** Map a category to its primary dimension bucket. */
    private static function primaryDimension(string $category): string
    {
        $security = [
            'sql_injection', 'xss', 'command_injection', 'code_injection',
            'insecure_deserialization', 'weak_cryptography', 'insecure_randomness',
            'path_traversal', 'ssrf', 'open_redirect', 'mass_assignment',
            'unguarded_mass_assignment', 'authorization', 'hardcoded_secret',
            'hardcoded_secrets', 'insecure_file_upload', 'csrf_disabled',
            'disabled_csrf', 'tls_verification', 'dynamic_include', 'debug_mode',
            'debug_code',
        ];
        $performance = [
            'n_plus_one', 'query_in_loop', 'collection_over_fetch', 'nested_loops',
            'missing_index', 'missing_cache',
        ];
        $architecture = [
            'fat_controller', 'fat_model', 'service_layer', 'solid',
            'business_logic_in_model', 'env_outside_config', 'large_class',
            'dependency_injection',
        ];

        if (in_array($category, $security, true)) {
            return 'security';
        }
        if (in_array($category, $performance, true)) {
            return 'performance';
        }
        if (in_array($category, $architecture, true)) {
            return 'architecture';
        }

        // Everything else (complexity, duplication, return types, magic numbers,
        // commented code, long params, god class, …) is maintainability.
        return 'maintainability';
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param string[]                       $categories
     * @return array<int,array<string,mixed>>
     */
    private static function collect(array $findings, array $categories): array
    {
        return array_values(array_filter(
            $findings,
            fn($f) => in_array((string) ($f['category'] ?? ''), $categories, true)
        ));
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @return array{critical:int,high:int,medium:int,low:int}
     */
    private static function severityCounts(array $findings): array
    {
        $counts = [Severity::CRITICAL => 0, Severity::HIGH => 0, Severity::MEDIUM => 0, Severity::LOW => 0];
        foreach ($findings as $f) {
            $counts[Severity::clamp($f['severity'] ?? '')]++;
        }
        return $counts;
    }

    /** @param array{critical:int,high:int,medium:int,low:int} $counts */
    private static function reasoning(string $label, string $blurb, int $score, array $counts): string
    {
        if ($counts[Severity::CRITICAL] + $counts[Severity::HIGH] + $counts[Severity::MEDIUM] + $counts[Severity::LOW] === 0) {
            return "{$blurb}: no issues detected — excellent.";
        }

        $bits = [];
        foreach ([Severity::CRITICAL => 'critical', Severity::HIGH => 'high', Severity::MEDIUM => 'medium', Severity::LOW => 'low'] as $sev => $word) {
            if ($counts[$sev] > 0) {
                $bits[] = "{$counts[$sev]} {$word}";
            }
        }

        $verdict = match (true) {
            $score >= 80 => 'in good shape',
            $score >= 60 => 'acceptable but improvable',
            $score >= 40 => 'needs attention',
            default      => 'at risk — prioritise',
        };

        return "{$blurb}: " . implode(', ', $bits) . " issue(s); {$verdict}.";
    }

    private static function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default      => 'F',
        };
    }
}
