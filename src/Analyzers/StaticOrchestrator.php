<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

use Illuminate\Support\Facades\File;

/**
 * Runs all built-in static analyzers against a set of files.
 * No API key, no internet, no cost — 100% embedded.
 */
class StaticOrchestrator
{
    private ArchitectureAnalyzer $architecture;
    private SecurityAnalyzer     $security;
    private PerformanceAnalyzer  $performance;
    private TechDebtAnalyzer     $techDebt;
    private StaticTestGenerator  $testGenerator;

    public function __construct()
    {
        $this->architecture  = new ArchitectureAnalyzer();
        $this->security      = new SecurityAnalyzer();
        $this->performance   = new PerformanceAnalyzer();
        $this->techDebt      = new TechDebtAnalyzer();
        $this->testGenerator = new StaticTestGenerator();
    }

    /**
     * Run full analysis on an array of files [$path => $content].
     *
     * @return array{
     *   files_scanned: int,
     *   total_lines: int,
     *   overall_score: int,
     *   grade: string,
     *   agents: array,
     *   all_findings: array,
     *   summary: array
     * }
     */
    public function analyze(array $files, array $options = [], string $scanPath = ''): array
    {
        $runArchitecture = $options['architecture'] ?? true;
        $runSecurity     = $options['security']     ?? true;
        $runPerformance  = $options['performance']  ?? true;
        $runTechDebt     = $options['tech_debt']    ?? true;

        $agentResults = [];
        $allFindings  = [];

        if ($runArchitecture) {
            $result          = $this->architecture->analyze($files);
            $agentResults[]  = $result;
            $allFindings     = array_merge($allFindings, $result['findings']);
        }

        if ($runSecurity) {
            $result         = $this->security->analyze($files);
            $agentResults[] = $result;
            $allFindings    = array_merge($allFindings, $result['findings']);
        }

        if ($runPerformance) {
            $result         = $this->performance->analyze($files);
            $agentResults[] = $result;
            $allFindings    = array_merge($allFindings, $result['findings']);
        }

        if ($runTechDebt) {
            $result         = $this->techDebt->analyze($files);
            $agentResults[] = $result;
            $allFindings    = array_merge($allFindings, $result['findings']);
        }

        $totalLines = array_sum(array_map(
            fn($c) => substr_count($c, "\n") + 1,
            $files
        ));

        $overallScore = $this->calculateOverallScore($agentResults);
        $grade        = $this->scoreToGrade($overallScore);
        $summary      = $this->buildSummary($allFindings, $agentResults);

        return [
            'files_scanned'  => count($files),
            'total_lines'    => $totalLines,
            'overall_score'  => $overallScore,
            'grade'          => $grade,
            'agents'         => $agentResults,
            'all_findings'   => $allFindings,
            'summary'        => $summary,
            'scan_path'      => $scanPath,
        ];
    }

    /**
     * Generate test files for a set of PHP source files.
     *
     * @return GeneratedTest[]
     */
    public function generateTests(array $files): array
    {
        $tests = [];

        foreach ($files as $filePath => $content) {
            // Only generate for PHP class files
            if (! str_ends_with($filePath, '.php')) {
                continue;
            }
            if (! preg_match('/\bclass\s+\w+/', $content)) {
                continue;
            }
            // Skip test files themselves
            if (str_contains($filePath, 'test') || str_contains($filePath, 'Test')) {
                continue;
            }

            $test = $this->testGenerator->generateForFile($filePath, $content);
            if ($test !== null) {
                $tests[] = $test;
            }
        }

        return $tests;
    }

    /**
     * Write generated test files to disk.
     *
     * @param  GeneratedTest[] $tests
     * @return array  list of written file paths
     */
    public function writeTests(array $tests, string $basePath): array
    {
        $written = [];

        foreach ($tests as $test) {
            $fullPath = $basePath . '/' . ltrim($test->filePath, '/');
            $dir      = dirname($fullPath);

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($fullPath, $test->content);
            $written[] = $fullPath;
        }

        return $written;
    }

    /**
     * Generate refactored version of a single file based on findings.
     * This is a deterministic, rule-based refactoring (no AI needed).
     */
    public function refactorFile(string $filePath, string $content, array $findings): RefactorResult
    {
        $refactored = $content;
        $changes    = [];

        foreach ($findings as $finding) {
            if ($finding['file'] !== $filePath) {
                continue;
            }

            switch ($finding['category']) {
                case 'mass_assignment':
                    [$refactored, $changed] = $this->fixMassAssignment($refactored);
                    if ($changed) $changes[] = 'Fixed mass assignment: replaced $request->all() with $request->validated()';
                    break;

                case 'debug_code':
                    [$refactored, $changed] = $this->removeDebugCode($refactored);
                    if ($changed) $changes[] = 'Removed debug statements (dd, dump, var_dump)';
                    break;

                case 'missing_types':
                    // Cannot safely auto-fix return types without full type inference
                    $changes[] = '[MANUAL] Add return type declarations to methods';
                    break;

                case 'magic_numbers':
                    // Too risky to auto-rename — mark for manual fix
                    $changes[] = '[MANUAL] Extract magic numbers to named constants';
                    break;

                case 'todo_debt':
                    // Just report — don't auto-remove TODOs
                    $changes[] = '[MANUAL] Resolve TODO/FIXME comments';
                    break;
            }
        }

        return new RefactorResult(
            filePath:    $filePath,
            original:    $content,
            refactored:  $refactored,
            changes:     $changes,
            autoFixed:   count(array_filter($changes, fn($c) => ! str_starts_with($c, '[MANUAL]'))),
            manualTodos: count(array_filter($changes, fn($c) => str_starts_with($c, '[MANUAL]'))),
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Deterministic auto-fix methods
    // ──────────────────────────────────────────────────────────────────────────

    private function fixMassAssignment(string $content): array
    {
        $patterns = [
            '/::create\s*\(\s*\$request->all\s*\(\s*\)\s*\)/'   => '::create($request->validated())',
            '/->update\s*\(\s*\$request->all\s*\(\s*\)\s*\)/'   => '->update($request->validated())',
            '/->fill\s*\(\s*\$request->all\s*\(\s*\)\s*\)/'     => '->fill($request->validated())',
        ];

        $changed = false;
        foreach ($patterns as $pattern => $replacement) {
            $new = preg_replace($pattern, $replacement, $content);
            if ($new !== $content) {
                $content = $new;
                $changed = true;
            }
        }

        return [$content, $changed];
    }

    private function removeDebugCode(string $content): array
    {
        $patterns = [
            '/^\s*dd\s*\([^;]*\);\s*$/m',
            '/^\s*dump\s*\([^;]*\);\s*$/m',
            '/^\s*var_dump\s*\([^;]*\);\s*$/m',
            '/^\s*print_r\s*\([^;]*\);\s*$/m',
        ];

        $changed = false;
        foreach ($patterns as $pattern) {
            $new = preg_replace($pattern, '', $content);
            if ($new !== $content) {
                $content = $new;
                $changed = true;
            }
        }

        // Clean up multiple blank lines left by removals
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        return [$content, $changed];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Score & summary helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function calculateOverallScore(array $agentResults): int
    {
        if (empty($agentResults)) {
            return 100;
        }

        $scores = [];
        foreach ($agentResults as $result) {
            // Iterate keys directly — array_key_first(array_filter(...)) returns
            // a numeric offset, not the string key name, so we avoid that pattern.
            foreach (array_keys($result) as $k) {
                if (str_ends_with($k, '_score')) {
                    $scores[] = $result[$k];
                    break;
                }
            }
        }

        return empty($scores) ? 100 : (int) round(array_sum($scores) / count($scores));
    }

    private function scoreToGrade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default      => 'F',
        };
    }

    private function buildSummary(array $allFindings, array $agentResults): array
    {
        $bySeverity  = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $byCategory  = [];
        $byFile      = [];

        foreach ($allFindings as $f) {
            $bySeverity[$f['severity']] = ($bySeverity[$f['severity']] ?? 0) + 1;
            $byCategory[$f['category']] = ($byCategory[$f['category']] ?? 0) + 1;
            $fileKey = basename($f['file']);
            $byFile[$fileKey] = ($byFile[$fileKey] ?? 0) + 1;
        }

        arsort($byCategory);
        arsort($byFile);

        // Sort all findings by severity so top-5 are the most critical
        $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($allFindings, fn($a, $b) =>
            ($severityOrder[$a['severity']] ?? 4) <=> ($severityOrder[$b['severity']] ?? 4)
        );
        $topIssues = array_slice($allFindings, 0, 5);

        return [
            'total_issues'   => count($allFindings),
            'by_severity'    => $bySeverity,
            'top_categories' => array_slice($byCategory, 0, 5, true),
            'hotspot_files'  => array_slice($byFile, 0, 5, true),
            'top_findings'   => array_map(fn($f) => [
                'severity' => $f['severity'],
                'category' => $f['category'],
                'title'    => $f['title'],
                'file'     => basename($f['file'] ?? ''),
            ], $topIssues),
        ];
    }
}

/**
 * Result of a deterministic refactor operation.
 */
class RefactorResult
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $original,
        public readonly string $refactored,
        public readonly array  $changes,
        public readonly int    $autoFixed,
        public readonly int    $manualTodos,
    ) {}

    public function hasChanges(): bool
    {
        return $this->original !== $this->refactored;
    }

    public function diff(): string
    {
        $before = explode("\n", $this->original);
        $after  = explode("\n", $this->refactored);
        $diff   = [];

        foreach ($after as $i => $line) {
            if (! isset($before[$i])) {
                $diff[] = "+ {$line}";
            } elseif ($before[$i] !== $line) {
                $diff[] = "- {$before[$i]}";
                $diff[] = "+ {$line}";
            }
        }

        return implode("\n", $diff);
    }
}
