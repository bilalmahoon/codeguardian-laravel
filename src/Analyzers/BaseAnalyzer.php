<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

use CodeGuardian\Laravel\Support\CachedPhpParser;
use CodeGuardian\Laravel\Support\FileTypeDetector;

abstract class BaseAnalyzer
{
    protected array $results = [];

    abstract public function getName(): string;

    /**
     * @param array<string,string> $files     [path => content]
     * @param callable|null        $onFile    optional per-file progress hook: fn(string $path): void
     */
    abstract public function analyze(array $files, ?callable $onFile = null): array;

    /** Safely invoke a per-file progress callback (no-op when null). */
    protected function tick(?callable $onFile, string $filePath): void
    {
        if ($onFile !== null) {
            $onFile($filePath);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Parsing
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Parse PHP file content into AST nodes.
     *
     * Delegates to CachedPhpParser — the parser is created ONCE for the process
     * lifetime and parse results are cached by content hash.
     * Returns null when the source is not valid PHP.
     *
     * @return list<\PhpParser\Node\Stmt>|null
     */
    protected function parse(string $code): ?array
    {
        return CachedPhpParser::parse($code);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Method-body extraction  (was duplicated in ArchitectureAnalyzer and TechDebtAnalyzer)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Extract the body of every method in $content.
     *
     * Returns an array of maps:
     *   [
     *     'name'       => string,   method name
     *     'start_line' => int,      1-based line of the "function" keyword
     *     'end_line'   => int,      1-based line of the closing brace
     *     'body'       => string,   full text of the method (from signature to closing brace)
     *     'body_lines' => int,      number of lines in the body (excluding signature)
     *   ]
     *
     * Uses brace-depth tracking — works correctly for nested classes / closures.
     */
    protected function extractMethods(string $content): array
    {
        preg_match_all(
            '/(?:public|private|protected)\s+(?:static\s+)?function\s+(\w+)\s*\([^)]*\)\s*(?::\s*\S+)?\s*\{/m',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $contentLines = explode("\n", $content);
        $totalLines   = count($contentLines);
        $methods      = [];

        foreach ($matches[1] as $i => $methodMatch) {
            $name         = $methodMatch[0];
            $signOffset   = $matches[0][$i][1];
            $startLine    = substr_count(substr($content, 0, $signOffset), "\n") + 1;

            // Walk forward tracking brace depth to find the closing brace
            $depth   = 0;
            $started = false;
            $endLine = $startLine;

            for ($ln = $startLine - 1; $ln < $totalLines && $ln < $startLine + 500; $ln++) {
                $line    = $contentLines[$ln];
                $depth  += substr_count($line, '{') - substr_count($line, '}');

                if (! $started && $depth > 0) {
                    $started = true;
                }
                if ($started && $depth <= 0) {
                    $endLine = $ln + 1; // 1-based
                    break;
                }
            }

            $bodyLineCount = max(0, $endLine - $startLine);
            $bodyText      = implode("\n", array_slice($contentLines, $startLine - 1, $bodyLineCount + 1));

            $methods[] = [
                'name'       => $name,
                'start_line' => $startLine,
                'end_line'   => $endLine,
                'body'       => $bodyText,
                'body_lines' => $bodyLineCount,
            ];
        }

        return $methods;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Line helpers
    // ──────────────────────────────────────────────────────────────────────────

    protected function lineCount(string $code): int
    {
        return substr_count($code, "\n") + 1;
    }

    protected function getLine(string $code, int $lineNumber): string
    {
        $lines = explode("\n", $code);
        return $lines[$lineNumber - 1] ?? '';
    }

    protected function getLines(string $code, int $start, int $end): string
    {
        $lines = explode("\n", $code);
        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // File-type helpers — delegate to FileTypeDetector (single source of truth)
    // ──────────────────────────────────────────────────────────────────────────

    protected function isController(string $filePath, string $content = ''): bool
    {
        return FileTypeDetector::isController($filePath, $content);
    }

    protected function isModel(string $filePath, string $content = ''): bool
    {
        return FileTypeDetector::isModel($filePath, $content);
    }

    protected function isService(string $filePath): bool
    {
        return FileTypeDetector::isService($filePath);
    }

    protected function isMigration(string $filePath): bool
    {
        return FileTypeDetector::isMigration($filePath);
    }

    protected function isTest(string $filePath): bool
    {
        return FileTypeDetector::isTest($filePath);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Cyclomatic complexity
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Calculate cyclomatic complexity of a method body string.
     * Counts decision points: if, elseif, foreach, for, while, case, catch, ??, &&, ||, ?
     */
    protected function cyclomaticComplexity(string $methodCode): int
    {
        $complexity = 1; // Base path
        $patterns   = [
            '/\bif\s*\(/i',
            '/\belseif\s*\(/i',
            '/\bforeach\s*\(/i',
            '/\bfor\s*\(/i',
            '/\bwhile\s*\(/i',
            '/\bcase\s+/i',
            '/\bcatch\s*\(/i',
            '/\?\?/',
            '/&&/',
            '/\|\|/',
            '/\?\s/',
        ];

        foreach ($patterns as $pattern) {
            $complexity += preg_match_all($pattern, $methodCode);
        }

        return $complexity;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Result management
    // ──────────────────────────────────────────────────────────────────────────

    protected function addResult(AnalysisResult $result): void
    {
        $this->results[] = $result;
    }

    /**
     * Flush accumulated results and reset for next file.
     *
     * @return array<int, array>
     */
    protected function flushResults(): array
    {
        $results       = array_map(fn($r) => $r->toArray(), $this->results);
        $this->results = [];
        return $results;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Score / summary helpers (shared by all concrete analyzers)
    // ──────────────────────────────────────────────────────────────────────────

    protected function calculateScore(array $findings): int
    {
        $score = 100;
        foreach ($findings as $f) {
            $score -= Severity::WEIGHTS[$f['severity']] ?? 0;
        }
        return max(0, min(100, $score));
    }

    protected function buildSummary(array $findings): array
    {
        $counts = [
            Severity::CRITICAL => 0,
            Severity::HIGH     => 0,
            Severity::MEDIUM   => 0,
            Severity::LOW      => 0,
        ];
        foreach ($findings as $f) {
            $sev = Severity::clamp($f['severity'] ?? '');
            $counts[$sev]++;
        }
        return array_merge(['total_issues' => count($findings)], $counts);
    }
}
