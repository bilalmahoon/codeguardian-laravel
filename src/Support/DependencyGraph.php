<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Builds a module-to-module dependency graph from `use` imports and detects
 * circular dependencies between modules. Pure (files in, graph out) so it can
 * be unit-tested without a real project; rendering to Mermaid/DOT is included.
 */
final class DependencyGraph
{
    /**
     * Build an adjacency list module => [modules it depends on].
     *
     * @param  array<string,string> $files    [relativePath => content]
     * @param  list<string>         $modules  module names
     * @return array<string,list<string>>
     */
    public static function build(array $files, array $modules): array
    {
        $graph = array_fill_keys($modules, []);

        foreach ($files as $path => $content) {
            $owner = self::moduleOf((string) $path, $modules);
            if ($owner === null) {
                continue;
            }

            preg_match_all('/^\s*use\s+([^;]+);/m', (string) $content, $uses);
            foreach ($uses[1] as $import) {
                foreach ($modules as $other) {
                    if ($other === $owner) {
                        continue;
                    }
                    if (preg_match('/(^|\\\\)' . preg_quote($other, '/') . '(\\\\|$)/', trim($import))) {
                        if (! in_array($other, $graph[$owner], true)) {
                            $graph[$owner][] = $other;
                        }
                    }
                }
            }
        }

        foreach ($graph as &$deps) {
            sort($deps);
        }

        return $graph;
    }

    /** Which module does this path belong to (path segment match), or null. */
    public static function moduleOf(string $path, array $modules): ?string
    {
        foreach ($modules as $m) {
            if (preg_match('#(^|/)' . preg_quote($m, '#') . '(/|$)#', $path)) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Detect circular dependencies. Returns each cycle as an ordered list of
     * modules (the first node repeated implicitly at the end).
     *
     * @param  array<string,list<string>> $graph
     * @return list<list<string>>
     */
    public static function cycles(array $graph): array
    {
        $cycles = [];
        $seen   = [];

        $visit = function (string $node, array $stack) use (&$visit, $graph, &$cycles, &$seen): void {
            if (in_array($node, $stack, true)) {
                // Found a cycle — slice the stack from the first occurrence.
                $idx   = array_search($node, $stack, true);
                $cycle = array_slice($stack, (int) $idx);

                // Canonicalize so the same cycle isn't reported twice.
                $key = self::canonicalCycleKey($cycle);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $cycles[]   = $cycle;
                }
                return;
            }

            $stack[] = $node;
            foreach ($graph[$node] ?? [] as $next) {
                $visit($next, $stack);
            }
        };

        foreach (array_keys($graph) as $node) {
            $visit($node, []);
        }

        return $cycles;
    }

    /** @param list<string> $cycle */
    private static function canonicalCycleKey(array $cycle): string
    {
        if ($cycle === []) {
            return '';
        }
        // Rotate so the lexicographically smallest node is first → stable key.
        $min = array_search(min($cycle), $cycle, true);
        $rotated = array_merge(array_slice($cycle, (int) $min), array_slice($cycle, 0, (int) $min));
        return implode('>', $rotated);
    }

    /**
     * @param  array<string,list<string>> $graph
     * @param  list<list<string>>         $cycles
     */
    public static function toMermaid(array $graph, array $cycles = []): string
    {
        $cycleEdges = [];
        foreach ($cycles as $cycle) {
            $n = count($cycle);
            for ($i = 0; $i < $n; $i++) {
                $from = $cycle[$i];
                $to   = $cycle[($i + 1) % $n];
                $cycleEdges["{$from}>{$to}"] = true;
            }
        }

        $lines = ['graph LR'];
        foreach ($graph as $module => $deps) {
            if ($deps === []) {
                $lines[] = "    {$module}";
                continue;
            }
            foreach ($deps as $dep) {
                $arrow   = isset($cycleEdges["{$module}>{$dep}"]) ? '-. cycle .->' : '-->';
                $lines[] = "    {$module} {$arrow} {$dep}";
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string,list<string>> $graph */
    public static function toDot(array $graph): string
    {
        $lines = ['digraph dependencies {', '    rankdir=LR;'];
        foreach ($graph as $module => $deps) {
            if ($deps === []) {
                $lines[] = "    \"{$module}\";";
                continue;
            }
            foreach ($deps as $dep) {
                $lines[] = "    \"{$module}\" -> \"{$dep}\";";
            }
        }
        $lines[] = '}';
        return implode("\n", $lines);
    }
}
