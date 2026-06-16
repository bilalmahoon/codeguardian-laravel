<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel;

use CodeGuardian\Laravel\Support\CodeScanner;

/**
 * Main entry point for programmatic usage.
 *
 * Usage:
 *   $results = app(CodeGuardian::class)->analyze('/path/to/project', 'laravel');
 *   $results = app(CodeGuardian::class)->security('/path/to/project');
 *   $results = app(CodeGuardian::class)->generateTests('/path/to/project');
 */
class CodeGuardian
{
    private CodeScanner         $scanner;
    private PackageOrchestrator $orchestrator;

    public function __construct(private readonly \Illuminate\Contracts\Foundation\Application $app)
    {
        $this->scanner      = new CodeScanner();
        $this->orchestrator = new PackageOrchestrator();
    }

    /**
     * Run a full analysis (all agents).
     */
    public function analyze(string $path, string $projectType = 'laravel', array|string $agents = 'all'): array
    {
        $context = $this->scanner->buildContext($path, $projectType);
        return $this->orchestrator->run($context, $agents);
    }

    /**
     * Run a security-only scan.
     */
    public function security(string $path, string $projectType = 'laravel'): array
    {
        $context = $this->scanner->buildContext($path, $projectType);
        return $this->orchestrator->runSecurityScan($context);
    }

    /**
     * Run a performance-only scan.
     */
    public function performance(string $path, string $projectType = 'laravel'): array
    {
        $context = $this->scanner->buildContext($path, $projectType);
        return $this->orchestrator->runPerformanceScan($context);
    }

    /**
     * Generate test cases for a project.
     */
    public function generateTests(string $path, string $projectType = 'laravel'): array
    {
        $context = $this->scanner->buildContext($path, $projectType);
        return $this->orchestrator->generateTests($context);
    }

    /**
     * Scan code files without running AI agents.
     * Useful to inspect what files will be analyzed.
     */
    public function scan(string $path, string $projectType = 'laravel'): array
    {
        return $this->scanner->buildContext($path, $projectType);
    }
}
