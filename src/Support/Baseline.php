<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use CodeGuardian\Laravel\Analyzers\Severity;

/**
 * Baseline / diff support — lets CI fail only on NEWLY introduced findings.
 *
 * A baseline is a snapshot of stable finding "fingerprints". On a later run we
 * compare the current findings against the baseline and partition them into:
 *   - new      : present now, absent from the baseline (regressions)
 *   - existing : present in both (known/accepted debt)
 *   - fixed    : in the baseline but gone now (improvements)
 *
 * Fingerprints deliberately EXCLUDE line numbers so unrelated edits above a
 * finding don't churn the baseline; identity is category + file + title +
 * normalised code snippet. Everything here is pure and unit-testable.
 */
final class Baseline
{
    public const VERSION = 1;

    /** Stable identity for a finding, independent of its line number. */
    public static function fingerprint(array $finding): string
    {
        $parts = [
            strtolower(trim((string) ($finding['category'] ?? ''))),
            self::normalisePath((string) ($finding['file'] ?? '')),
            strtolower(trim((string) ($finding['title'] ?? ''))),
            self::normaliseSnippet((string) ($finding['code_snippet'] ?? '')),
        ];

        return substr(sha1(implode('|', $parts)), 0, 16);
    }

    /**
     * Build a baseline document from a set of findings.
     *
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    public static function create(array $findings): array
    {
        $fingerprints = [];
        foreach ($findings as $f) {
            $fp = self::fingerprint($f);
            $fingerprints[$fp] = [
                'category' => $f['category'] ?? '',
                'file'     => $f['file'] ?? '',
                'title'    => $f['title'] ?? '',
                'severity' => $f['severity'] ?? '',
            ];
        }

        return [
            'version'      => self::VERSION,
            'tool'         => 'codeguardian',
            'generated_at' => gmdate('c'),
            'count'        => count($fingerprints),
            'fingerprints' => $fingerprints,
        ];
    }

    /**
     * Partition current findings against a baseline document.
     *
     * @param array<int,array<string,mixed>> $current
     * @param array<string,mixed>            $baseline
     * @return array{new: array<int,array<string,mixed>>, existing: array<int,array<string,mixed>>, fixed: array<int,array<string,mixed>>}
     */
    public static function diff(array $current, array $baseline): array
    {
        $known = $baseline['fingerprints'] ?? [];

        $new      = [];
        $existing = [];
        $seen     = [];

        foreach ($current as $f) {
            $fp        = self::fingerprint($f);
            $seen[$fp] = true;
            if (isset($known[$fp])) {
                $existing[] = $f;
            } else {
                $new[] = $f;
            }
        }

        $fixed = [];
        foreach ($known as $fp => $meta) {
            if (! isset($seen[$fp])) {
                $fixed[] = $meta;
            }
        }

        return ['new' => $new, 'existing' => $existing, 'fixed' => $fixed];
    }

    /**
     * Reduce a full analyze() result to only the supplied findings (by
     * fingerprint), recomputing summary counts so reports/exit-codes are
     * consistent. Used by --new-only.
     *
     * @param array<string,mixed>            $results
     * @param array<int,array<string,mixed>> $keep
     * @return array<string,mixed>
     */
    public static function restrict(array $results, array $keep): array
    {
        $keepSet = [];
        foreach ($keep as $f) {
            $keepSet[self::fingerprint($f)] = true;
        }

        $filter = fn(array $list) => array_values(array_filter(
            $list,
            fn($f) => isset($keepSet[self::fingerprint($f)])
        ));

        if (isset($results['all_findings']) && is_array($results['all_findings'])) {
            $results['all_findings'] = $filter($results['all_findings']);
        }

        if (isset($results['agent_results']) && is_array($results['agent_results'])) {
            foreach ($results['agent_results'] as $agent => $data) {
                if (isset($data['findings']) && is_array($data['findings'])) {
                    $results['agent_results'][$agent]['findings'] = $filter($data['findings']);
                }
            }
        }

        $all    = $results['all_findings'] ?? [];
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($all as $f) {
            $counts[Severity::clamp($f['severity'] ?? '')]++;
        }

        $summary                 = $results['summary'] ?? [];
        $summary['total_issues'] = count($all);
        $summary['critical']     = $counts['critical'];
        $summary['high']         = $counts['high'];
        $summary['medium']       = $counts['medium'];
        $summary['low']          = $counts['low'];
        if (isset($summary['by_severity'])) {
            $summary['by_severity'] = $counts;
        }

        $sorted = $all;
        usort($sorted, fn($a, $b) =>
            (Severity::ORDER[Severity::clamp($a['severity'] ?? '')] ?? 4)
            <=> (Severity::ORDER[Severity::clamp($b['severity'] ?? '')] ?? 4)
        );
        $summary['top_findings'] = array_slice($sorted, 0, 10);

        $results['summary'] = $summary;

        return $results;
    }

    private static function normalisePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    private static function normaliseSnippet(string $snippet): string
    {
        return trim(preg_replace('/\s+/', ' ', $snippet) ?? $snippet);
    }
}
