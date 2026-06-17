<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\Node;

abstract class BaseAnalyzer
{
    protected array $results = [];

    abstract public function getName(): string;

    abstract public function analyze(array $files): array;

    /**
     * Parse PHP file content into AST nodes.
     * Returns null on parse error.
     */
    protected function parse(string $code): ?array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            return $parser->parse($code);
        } catch (Error) {
            return null;
        }
    }

    /**
     * Count lines in a code string.
     */
    protected function lineCount(string $code): int
    {
        return substr_count($code, "\n") + 1;
    }

    /**
     * Get a specific line from code.
     */
    protected function getLine(string $code, int $lineNumber): string
    {
        $lines = explode("\n", $code);
        return $lines[$lineNumber - 1] ?? '';
    }

    /**
     * Extract lines range from code.
     */
    protected function getLines(string $code, int $start, int $end): string
    {
        $lines = explode("\n", $code);
        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }

    /**
     * Check if file is likely a controller.
     */
    protected function isController(string $filePath): bool
    {
        return str_contains($filePath, 'Controller') ||
               str_contains($filePath, 'controllers') ||
               str_contains($filePath, 'Controllers');
    }

    /**
     * Check if file is likely a model.
     */
    protected function isModel(string $filePath): bool
    {
        return str_contains($filePath, '/Models/') ||
               str_contains($filePath, '/Model/') ||
               (str_contains($filePath, 'app/') && !$this->isController($filePath) && !$this->isService($filePath));
    }

    /**
     * Check if file is likely a service.
     */
    protected function isService(string $filePath): bool
    {
        return str_contains($filePath, 'Service') ||
               str_contains($filePath, 'services') ||
               str_contains($filePath, 'Services');
    }

    /**
     * Add a finding.
     */
    protected function addResult(AnalysisResult $result): void
    {
        $this->results[] = $result;
    }

    /**
     * Return results as array and reset.
     */
    protected function flushResults(): array
    {
        $results       = array_map(fn($r) => $r->toArray(), $this->results);
        $this->results = [];
        return $results;
    }

    /**
     * Calculate cyclomatic complexity of a method body (rough estimate).
     * Counts: if, elseif, else, foreach, for, while, case, catch, && ||
     */
    protected function cyclomaticComplexity(string $methodCode): int
    {
        $complexity = 1;
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
}
