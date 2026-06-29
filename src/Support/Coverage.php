<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Parses a Clover coverage report and flags complex/large code paths that have
 * little or no test coverage — the highest-risk gaps. Pure + testable.
 */
final class Coverage
{
    /**
     * Parse Clover XML into per-file coverage percentages (0–100).
     *
     * @return array<string,float>  [normalisedPath => percent]
     */
    public static function fromClover(string $xml, string $projectRoot = ''): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);

        if ($doc === false) {
            return [];
        }

        $coverage = [];
        foreach ($doc->xpath('//file') ?: [] as $file) {
            $name = (string) ($file['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $metrics = $file->metrics ?? null;
            if ($metrics === null) {
                continue;
            }
            $elements = (int) ($metrics['elements'] ?? 0);
            $covered  = (int) ($metrics['coveredelements'] ?? 0);
            $percent  = $elements > 0 ? round($covered / $elements * 100, 1) : 0.0;

            $coverage[self::relative($name, $projectRoot)] = $percent;
        }

        return $coverage;
    }

    /**
     * Emit findings for files that have complexity/size debt AND coverage below
     * the threshold — untested complex code is where bugs hide.
     *
     * @param array<string,float>            $coverage  [path => percent]
     * @param array<int,array<string,mixed>> $findings  existing findings
     * @return array<int,array<string,mixed>>
     */
    public static function flagUntested(array $coverage, array $findings, float $threshold = 50.0): array
    {
        if ($coverage === []) {
            return [];
        }

        // Files the engine already flagged as complex/large/high-risk.
        $riskyCats = ['high_complexity', 'large_class', 'god_class', 'duplication', 'deep_nesting'];
        $risky     = [];
        foreach ($findings as $f) {
            if (in_array((string) ($f['category'] ?? ''), $riskyCats, true)) {
                $file = str_replace('\\', '/', (string) ($f['file'] ?? ''));
                if ($file !== '') {
                    $risky[$file] = true;
                }
            }
        }

        $out = [];
        foreach (array_keys($risky) as $file) {
            $percent = self::lookup($coverage, $file);
            if ($percent === null || $percent >= $threshold) {
                continue;
            }
            $out[] = [
                'severity'       => $percent <= 0.0 ? 'high' : 'medium',
                'category'       => 'untested_complexity',
                'title'          => sprintf('Complex code with %s%% test coverage', rtrim(rtrim((string) $percent, '0'), '.')),
                'description'    => 'This file was flagged for complexity/size but has low test coverage. '
                    . 'Untested complex code is the most likely place for regressions.',
                'file'           => $file,
                'line_start'     => 0,
                'recommendation' => 'Add unit tests covering the complex branches before (or alongside) refactoring.',
                'confidence'     => 'high',
                'source'         => 'coverage',
                'coverage'       => $percent,
            ];
        }

        return $out;
    }

    /** Match a finding file path against the coverage map (suffix-aware). */
    private static function lookup(array $coverage, string $file): ?float
    {
        $file = str_replace('\\', '/', $file);
        if (isset($coverage[$file])) {
            return $coverage[$file];
        }
        foreach ($coverage as $path => $percent) {
            if (str_ends_with($path, '/' . $file) || str_ends_with($file, '/' . $path)) {
                return $percent;
            }
        }
        return null;
    }

    private static function relative(string $file, string $projectRoot): string
    {
        $file = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
        if ($root !== '' && str_starts_with($file, $root . '/')) {
            return ltrim(substr($file, strlen($root)), '/');
        }
        return $file;
    }
}
