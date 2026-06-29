<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Project-defined regex detection rules (config 'custom_rules'). Lets teams
 * encode their own conventions without writing an analyzer. Pure + testable.
 */
final class CustomRules
{
    /** Hard cap on matches per (file, rule) so a broad pattern can't explode. */
    private const MAX_MATCHES_PER_FILE = 50;

    /**
     * Normalise raw config into validated rule specs (invalid rules dropped).
     *
     * @param array<int,mixed> $config
     * @return array<int,array{id:string,title:string,pattern:string,severity:string,message:string,fix:string,paths:array<int,string>,exclude:array<int,string>}>
     */
    public static function fromConfig(array $config): array
    {
        $specs = [];
        foreach ($config as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $id      = strtolower(trim((string) ($raw['id'] ?? '')));
            $pattern = (string) ($raw['pattern'] ?? '');
            $title   = trim((string) ($raw['title'] ?? $id));
            if ($id === '' || $pattern === '' || $title === '') {
                continue;
            }
            // Reject patterns that don't compile (avoid runtime warnings later).
            if (@preg_match(self::delimit($pattern), '') === false) {
                continue;
            }

            $sev = strtolower((string) ($raw['severity'] ?? 'medium'));
            $specs[] = [
                'id'       => $id,
                'title'    => $title,
                'pattern'  => $pattern,
                'severity' => in_array($sev, ['critical', 'high', 'medium', 'low'], true) ? $sev : 'medium',
                'message'  => trim((string) ($raw['message'] ?? $title)),
                'fix'      => trim((string) ($raw['fix'] ?? '')),
                'paths'    => self::stringList($raw['paths'] ?? []),
                'exclude'  => self::stringList($raw['exclude'] ?? []),
            ];
        }

        return $specs;
    }

    /**
     * Run every spec over the file set and return findings (engine-compatible).
     *
     * @param array<string,string> $files  [relPath => content]
     * @param array<int,array<string,mixed>> $specs
     * @return array<int,array<string,mixed>>
     */
    public static function run(array $files, array $specs): array
    {
        if ($specs === []) {
            return [];
        }

        $findings = [];
        foreach ($files as $path => $content) {
            foreach ($specs as $spec) {
                if (! self::pathMatches($path, $spec['paths'], $spec['exclude'])) {
                    continue;
                }
                foreach (self::matchLines($content, $spec['pattern']) as $hit) {
                    $findings[] = [
                        'severity'       => $spec['severity'],
                        'category'       => $spec['id'],
                        'title'          => $spec['title'],
                        'description'    => $spec['message'],
                        'file'           => $path,
                        'line_start'     => $hit['line'],
                        'code_snippet'   => $hit['snippet'],
                        'recommendation' => $spec['fix'] !== '' ? $spec['fix'] : null,
                        'confidence'     => 'medium',
                        'source'         => 'custom_rule',
                    ];
                }
            }
        }

        return $findings;
    }

    /** @return array<int,array{line:int,snippet:string}> */
    private static function matchLines(string $content, string $pattern): array
    {
        $regex = self::delimit($pattern);
        if (! @preg_match_all($regex, $content, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $hits = [];
        foreach ($m[0] as $match) {
            $offset = (int) $match[1];
            $line   = substr_count($content, "\n", 0, $offset) + 1;
            $hits[] = [
                'line'    => $line,
                'snippet' => trim(self::lineAt($content, $line)),
            ];
            if (count($hits) >= self::MAX_MATCHES_PER_FILE) {
                break;
            }
        }

        return $hits;
    }

    private static function lineAt(string $content, int $line): string
    {
        $lines = explode("\n", $content);
        return $lines[$line - 1] ?? '';
    }

    private static function pathMatches(string $path, array $paths, array $exclude): bool
    {
        foreach ($exclude as $needle) {
            if ($needle !== '' && str_contains($path, $needle)) {
                return false;
            }
        }
        if ($paths === []) {
            return true;
        }
        foreach ($paths as $needle) {
            if ($needle !== '' && str_contains($path, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function delimit(string $pattern): string
    {
        // User patterns are written without delimiters; default to case-insensitive.
        return '/' . str_replace('/', '\/', $pattern) . '/i';
    }

    /** @param mixed $value @return array<int,string> */
    private static function stringList($value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (! is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(fn($v) => trim((string) $v), $value), fn($v) => $v !== ''));
    }
}
