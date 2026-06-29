<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use CodeGuardian\Laravel\Analyzers\Severity;

/**
 * Noise control: suppress findings via project config or inline source comments.
 *
 * Config (config/codeguardian.php → 'ignore'):
 *   - categories : finding categories to always drop (e.g. ['magic_numbers'])
 *   - paths      : path substrings or globs to drop findings from
 *                  (e.g. ['database/migrations/', 'tests/*'])
 *
 * Inline (in the source itself), using the marker `codeguardian-ignore`:
 *   - `// codeguardian-ignore`                  → drop any finding on this line
 *   - `// codeguardian-ignore sql_injection`    → drop only that category here
 *   - line above a statement counts too (so the comment can sit on its own line)
 *   - `// codeguardian-ignore-file`             → drop every finding in the file
 *
 * The matching logic is pure; file access is injected as a reader callable so it
 * can be unit-tested without the filesystem.
 */
final class Suppressor
{
    public const MARKER = 'codeguardian-ignore';

    /**
     * Normalise the raw config array into a spec.
     *
     * @param array<string,mixed> $config
     * @return array{categories: array<int,string>, paths: array<int,string>, marker: string}
     */
    public static function specFromConfig(array $config): array
    {
        return [
            'categories' => array_values(array_map('strtolower', (array) ($config['categories'] ?? []))),
            'paths'      => array_values(array_map(
                fn($p) => strtolower(str_replace('\\', '/', (string) $p)),
                (array) ($config['paths'] ?? [])
            )),
            'marker'     => (string) ($config['inline_marker'] ?? self::MARKER),
        ];
    }

    /**
     * Apply suppression to a full analyze() result, recomputing summary counts.
     *
     * @param array<string,mixed>   $results
     * @param array<string,mixed>   $spec
     * @param callable(string):?string $reader  maps a finding's file path to its content
     * @return array{0: array<string,mixed>, 1: int}  [results, suppressedCount]
     */
    public static function applyToResult(array $results, array $spec, callable $reader): array
    {
        $before = count($results['all_findings'] ?? []);

        $contentCache = [];
        $keep = function (array $f) use ($spec, $reader, &$contentCache): bool {
            return ! self::shouldSuppress($f, $spec, $reader, $contentCache);
        };

        if (isset($results['all_findings']) && is_array($results['all_findings'])) {
            $results['all_findings'] = array_values(array_filter($results['all_findings'], $keep));
        }

        if (isset($results['agent_results']) && is_array($results['agent_results'])) {
            foreach ($results['agent_results'] as $agent => $data) {
                if (isset($data['findings']) && is_array($data['findings'])) {
                    $results['agent_results'][$agent]['findings'] =
                        array_values(array_filter($data['findings'], $keep));
                }
            }
        }

        $results = self::recomputeSummary($results);

        $after = count($results['all_findings'] ?? []);

        return [$results, max(0, $before - $after)];
    }

    /**
     * Decide whether a single finding should be suppressed.
     *
     * @param array<string,mixed>      $finding
     * @param array<string,mixed>      $spec
     * @param callable(string):?string $reader
     * @param array<string,?string>    $cache    keyed file-content cache (by ref)
     */
    public static function shouldSuppress(array $finding, array $spec, callable $reader, array &$cache = []): bool
    {
        if (self::configSuppresses($finding, $spec)) {
            return true;
        }

        $file = (string) ($finding['file'] ?? '');
        if ($file === '') {
            return false;
        }

        if (! array_key_exists($file, $cache)) {
            $cache[$file] = $reader($file);
        }
        $content = $cache[$file];
        if (! is_string($content) || $content === '') {
            return false;
        }

        return self::inlineSuppresses($finding, $content, $spec['marker'] ?? self::MARKER);
    }

    // ─── Config matching ─────────────────────────────────────────────────────

    /** @param array<string,mixed> $f @param array<string,mixed> $spec */
    private static function configSuppresses(array $f, array $spec): bool
    {
        $category = strtolower((string) ($f['category'] ?? ''));
        if (in_array($category, $spec['categories'] ?? [], true)) {
            return true;
        }

        $path = strtolower(str_replace('\\', '/', (string) ($f['file'] ?? '')));
        foreach ($spec['paths'] ?? [] as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (str_contains($pattern, '*')) {
                if (fnmatch($pattern, $path)) {
                    return true;
                }
            } elseif (str_contains($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    // ─── Inline matching ─────────────────────────────────────────────────────

    public static function inlineSuppresses(array $finding, string $content, string $marker = self::MARKER): bool
    {
        $lines = explode("\n", $content);

        // File-level: anywhere in the file.
        foreach ($lines as $line) {
            if (stripos($line, $marker . '-file') !== false) {
                return true;
            }
        }

        $category = (string) ($finding['category'] ?? '');
        $lineNo   = max(1, (int) ($finding['line_start'] ?? 1));

        // The finding's own line and the line directly above it.
        foreach ([$lineNo, $lineNo - 1] as $ln) {
            $idx = $ln - 1;
            if ($idx < 0 || ! isset($lines[$idx])) {
                continue;
            }
            if (self::lineSuppresses($lines[$idx], $category, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function lineSuppresses(string $line, string $category, string $marker): bool
    {
        $pos = stripos($line, $marker);
        if ($pos === false) {
            return false;
        }

        $rest = substr($line, $pos + strlen($marker));

        // A `-file` directive is handled at file level, not per-line.
        if (preg_match('/^\s*-file\b/i', $rest)) {
            return false;
        }

        // Allow an optional `-line` qualifier, then optional category list.
        $rest = preg_replace('/^\s*-line\b/i', '', $rest) ?? $rest;
        $rest = ltrim($rest, " \t:");

        if (! preg_match_all('/[a-z0-9_]+/i', $rest, $m) || empty($m[0])) {
            return true; // bare marker → suppress anything on this line
        }

        $cats = array_map('strtolower', $m[0]);
        return in_array(strtolower($category), $cats, true);
    }

    // ─── Summary recompute ───────────────────────────────────────────────────

    /** @param array<string,mixed> $results @return array<string,mixed> */
    private static function recomputeSummary(array $results): array
    {
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
}
