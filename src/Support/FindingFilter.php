<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use CodeGuardian\Laravel\Analyzers\Severity;

/**
 * Filters a flat list of findings by optional, combinable criteria.
 *
 * All criteria are AND-combined. Each individual criterion is OR-combined
 * (e.g. severity=critical,high keeps findings that are critical OR high).
 * Passing an empty filter set returns the findings untouched, so the filter
 * is fully backward compatible — commands only filter when a flag is given.
 *
 * Supported keys:
 *   severity      list — exact severity match (critical|high|medium|low)
 *   min-severity  string — keep findings at least this severe
 *   category      list — substring match against the finding category
 *   confidence    list — exact confidence match (high|medium|low)
 *   owasp         list — substring match against the OWASP tag
 *   cwe           list — substring match against the CWE id
 *   principle     list — substring match against the principle tag
 */
class FindingFilter
{
    /**
     * Build a normalised filter spec from raw (CSV) option values.
     *
     * @param  array<string,?string> $options  e.g. ['severity' => 'high,critical']
     * @return array<string,mixed>
     */
    public static function fromOptions(array $options): array
    {
        $spec = [];

        foreach (['severity', 'category', 'confidence', 'owasp', 'cwe', 'principle'] as $key) {
            $list = self::csv($options[$key] ?? null);
            if (! empty($list)) {
                $spec[$key] = $list;
            }
        }

        $min = $options['min-severity'] ?? null;
        if (is_string($min) && trim($min) !== '') {
            $spec['min-severity'] = strtolower(trim($min));
        }

        return $spec;
    }

    public static function isEmpty(array $spec): bool
    {
        return $spec === [];
    }

    /**
     * Apply the filter spec to a flat list of findings.
     *
     * @param  array<int,array<string,mixed>> $findings
     * @param  array<string,mixed>            $spec
     * @return array<int,array<string,mixed>>
     */
    public static function apply(array $findings, array $spec): array
    {
        if (self::isEmpty($spec)) {
            return $findings;
        }

        return array_values(array_filter($findings, fn($f) => self::matches($f, $spec)));
    }

    /**
     * Apply the filter to a full analyze() result structure: the flat
     * all_findings list, each agent's findings, and recompute summary counts.
     *
     * @param  array<string,mixed> $results
     * @param  array<string,mixed> $spec
     * @return array<string,mixed>
     */
    public static function applyToResult(array $results, array $spec): array
    {
        if (self::isEmpty($spec)) {
            return $results;
        }

        if (isset($results['all_findings']) && is_array($results['all_findings'])) {
            $results['all_findings'] = self::apply($results['all_findings'], $spec);
        }

        $all = $results['all_findings'] ?? [];

        if (isset($results['agent_results']) && is_array($results['agent_results'])) {
            foreach ($results['agent_results'] as $agent => $data) {
                if (isset($data['findings']) && is_array($data['findings'])) {
                    $results['agent_results'][$agent]['findings'] = self::apply($data['findings'], $spec);
                }
            }
        }

        // Recompute severity counts + total from the filtered set.
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($all as $f) {
            $sev = Severity::clamp($f['severity'] ?? '');
            $counts[$sev]++;
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
        // Refresh top findings (most severe first) from the filtered set.
        $sorted = $all;
        usort($sorted, fn($a, $b) =>
            (Severity::ORDER[Severity::clamp($a['severity'] ?? '')] ?? 4)
            <=> (Severity::ORDER[Severity::clamp($b['severity'] ?? '')] ?? 4)
        );
        $summary['top_findings'] = array_slice($sorted, 0, 10);
        $results['summary']      = $summary;

        return $results;
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    private static function matches(array $f, array $spec): bool
    {
        if (isset($spec['severity'])
            && ! in_array(strtolower((string) ($f['severity'] ?? '')), $spec['severity'], true)) {
            return false;
        }

        if (isset($spec['min-severity'])
            && ! Severity::atLeast((string) ($f['severity'] ?? 'low'), $spec['min-severity'])) {
            return false;
        }

        if (isset($spec['confidence'])
            && ! in_array(strtolower((string) ($f['confidence'] ?? 'medium')), $spec['confidence'], true)) {
            return false;
        }

        if (isset($spec['category']) && ! self::containsAny((string) ($f['category'] ?? ''), $spec['category'])) {
            return false;
        }

        if (isset($spec['owasp']) && ! self::containsAny((string) ($f['owasp'] ?? ''), $spec['owasp'])) {
            return false;
        }

        if (isset($spec['cwe']) && ! self::containsAny((string) ($f['cwe'] ?? ''), $spec['cwe'])) {
            return false;
        }

        if (isset($spec['principle']) && ! self::containsAny((string) ($f['principle'] ?? ''), $spec['principle'])) {
            return false;
        }

        return true;
    }

    /** @param array<int,string> $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        $haystack = strtolower($haystack);
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Split a CSV option value into a lowercased, trimmed list.
     *
     * @return array<int,string>
     */
    private static function csv(?string $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn($p) => strtolower(trim($p)),
            explode(',', $value)
        ), fn($p) => $p !== ''));
    }
}
