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
     * Deterministic, rule-based refactoring of a single file.
     * No AI API call required.
     *
     * Auto-fixes: mass assignment ($request->all()), debug statements (dd/dump).
     * All other categories produce actionable [MANUAL] guidance, one entry per
     * category (not one per finding instance).
     */
    public function refactorFile(string $filePath, string $content, array $findings): RefactorResult
    {
        $refactored        = $content;
        $changes           = [];
        $appliedCategories = []; // prevent duplicate messages for same category

        foreach ($findings as $finding) {
            $cat = $finding['category'] ?? 'unknown';

            // Guard: skip findings that don't belong to this file
            if (($finding['file'] ?? '') !== $filePath && ($finding['file'] ?? '') !== '') {
                continue;
            }

            // Already handled this category in this pass
            if (in_array($cat, $appliedCategories, true)) {
                continue;
            }

            switch ($cat) {
                // ── Auto-fixable ──────────────────────────────────────────────
                case 'mass_assignment':
                    [$refactored, $fixed] = $this->fixMassAssignment($refactored);
                    if ($fixed) {
                        $changes[] = 'Auto-fixed: replaced $request->all() with $request->validated()';
                        $appliedCategories[] = $cat;
                    }
                    break;

                case 'debug_code':
                    [$refactored, $fixed] = $this->removeDebugCode($refactored);
                    if ($fixed) {
                        $changes[] = 'Auto-fixed: removed debug statements (dd, dump, var_dump)';
                        $appliedCategories[] = $cat;
                    }
                    break;

                // ── Manual fixes — architecture ───────────────────────────────
                case 'fat_controller':
                    $changes[] = '[MANUAL] Fat controller — extract business logic to a dedicated Service class';
                    $appliedCategories[] = $cat;
                    break;

                case 'service_layer':
                    $changes[] = '[MANUAL] Missing service layer — create a Service class and inject it via constructor';
                    $appliedCategories[] = $cat;
                    break;

                case 'solid':
                    $changes[] = '[MANUAL] SOLID violation — extract inline validation to a FormRequest class';
                    $appliedCategories[] = $cat;
                    break;

                case 'dependency_injection':
                    $changes[] = '[MANUAL] Heavy static facade use — inject dependencies via constructor instead';
                    $appliedCategories[] = $cat;
                    break;

                // ── Manual fixes — security ───────────────────────────────────
                case 'sql_injection':
                    $line = $finding['line_start'] ?? '?';
                    $changes[] = "[MANUAL] SQL injection risk at line {$line} — use parameterized bindings: DB::select('...', [\$var])";
                    $appliedCategories[] = $cat;
                    break;

                case 'secret_exposure':
                    $changes[] = '[MANUAL] Hardcoded secret — move to .env and access via env() or config()';
                    $appliedCategories[] = $cat;
                    break;

                case 'authorization':
                    $changes[] = '[MANUAL] Missing authorization — add $this->authorize() or use a Policy class';
                    $appliedCategories[] = $cat;
                    break;

                case 'xss':
                    $changes[] = '[MANUAL] Unescaped output {!! !!} — use {{ }} unless HTML is explicitly sanitized';
                    $appliedCategories[] = $cat;
                    break;

                case 'insecure_upload':
                    $changes[] = "[MANUAL] File upload lacks MIME validation — add: 'file' => 'required|file|mimes:jpg,png,pdf|max:10240'";
                    $appliedCategories[] = $cat;
                    break;

                // ── Manual fixes — performance ────────────────────────────────
                case 'n_plus_one':
                case 'eager_loading':
                    $changes[] = '[MANUAL] N+1 query — add eager loading: ->with([\'relationship\'])';
                    $appliedCategories[] = $cat;
                    break;

                case 'select_all':
                    $changes[] = '[MANUAL] Model::all() without pagination — replace with ->paginate(25)';
                    $appliedCategories[] = $cat;
                    break;

                case 'missing_cache':
                    $changes[] = '[MANUAL] Complex query without caching — wrap in Cache::remember(\'key\', 3600, fn() => ...)';
                    $appliedCategories[] = $cat;
                    break;

                case 'inefficient_count':
                    $changes[] = '[MANUAL] count() on collection — replace with Model::where(...)->count() query';
                    $appliedCategories[] = $cat;
                    break;

                case 'memory_usage':
                    $changes[] = '[MANUAL] Bulk operation without chunking — use Model::chunk(500, fn(...) => ...)';
                    $appliedCategories[] = $cat;
                    break;

                case 'missing_index':
                    $changes[] = '[MANUAL] Potential missing DB index — add $table->index(\'field\') in migration';
                    $appliedCategories[] = $cat;
                    break;

                // ── Manual fixes — tech debt ──────────────────────────────────
                case 'large_class':
                    $changes[] = '[MANUAL] Large class — split into focused classes by responsibility';
                    $appliedCategories[] = $cat;
                    break;

                case 'high_complexity':
                    $changes[] = '[MANUAL] High cyclomatic complexity — break method into smaller focused methods';
                    $appliedCategories[] = $cat;
                    break;

                case 'deep_nesting':
                    $changes[] = '[MANUAL] Deep nesting — use early returns (guard clauses) to flatten logic';
                    $appliedCategories[] = $cat;
                    break;

                case 'missing_types':
                    $count     = count(array_filter($findings, fn($f) => ($f['category'] ?? '') === 'missing_types'));
                    $changes[] = "[MANUAL] Missing return type declarations — add PHP 8.1 types to {$count} method(s)";
                    $appliedCategories[] = $cat;
                    break;

                case 'magic_numbers':
                    $changes[] = '[MANUAL] Magic numbers — extract to named constants (const MAX_ATTEMPTS = 5)';
                    $appliedCategories[] = $cat;
                    break;

                case 'todo_debt':
                    $count     = count(array_filter($findings, fn($f) => ($f['category'] ?? '') === 'todo_debt'));
                    $changes[] = "[MANUAL] {$count} TODO/FIXME comment(s) — create tickets and resolve before release";
                    $appliedCategories[] = $cat;
                    break;

                case 'dead_code':
                    $changes[] = '[MANUAL] Commented-out dead code — delete it (git history preserves it)';
                    $appliedCategories[] = $cat;
                    break;

                case 'duplication':
                    $changes[] = '[MANUAL] Duplicated code block — extract to a shared Trait, Service, or Base class';
                    $appliedCategories[] = $cat;
                    break;

                default:
                    // Unknown category — still surface it so the user isn't left with silence
                    $label     = ucwords(str_replace('_', ' ', $cat));
                    $rec       = $finding['recommendation'] ?? "Review and fix {$label} manually.";
                    $changes[] = "[MANUAL] {$label}: {$rec}";
                    $appliedCategories[] = $cat;
                    break;
            }
        }

        return new RefactorResult(
            filePath:    $filePath,
            original:    $content,
            refactored:  $refactored,
            changes:     $changes,
            autoFixed:   count(array_filter($changes, fn($c) => str_starts_with($c, 'Auto-fixed:'))),
            manualTodos: count(array_filter($changes, fn($c) => str_starts_with($c, '[MANUAL]'))),
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Deterministic auto-fix methods
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Apply a regex replacement safely.
     * Returns null if PCRE encounters an error (do NOT write null to disk).
     */
    private function safeReplace(string $pattern, string $replacement, string $content): ?string
    {
        $result = preg_replace($pattern, $replacement, $content);
        return ($result === null) ? null : $result;
    }

    private function fixMassAssignment(string $content): array
    {
        // Match ->update/::create/->fill followed by $request->all() even with extra args
        $patterns = [
            '/::create\s*\(\s*\$request->all\s*\(\s*\)/'   => '::create($request->validated(',
            '/->update\s*\(\s*\$request->all\s*\(\s*\)/'   => '->update($request->validated(',
            '/->fill\s*\(\s*\$request->all\s*\(\s*\)\s*\)/' => '->fill($request->validated())',
        ];

        $changed = false;
        foreach ($patterns as $pattern => $replacement) {
            $new = $this->safeReplace($pattern, $replacement, $content);
            if ($new !== null && $new !== $content) {
                $content = $new;
                $changed = true;
            }
        }

        return [$content, $changed];
    }

    private function removeDebugCode(string $content): array
    {
        // Matches single-line debug calls: dd(...); dump(...); var_dump(...); print_r(...);
        // Multi-line calls are NOT auto-removed (too risky) — they appear as [MANUAL] from SecurityAnalyzer
        $patterns = [
            '/^[ \t]*dd\s*\(.*\);\s*$/m',
            '/^[ \t]*dump\s*\(.*\);\s*$/m',
            '/^[ \t]*var_dump\s*\(.*\);\s*$/m',
            '/^[ \t]*print_r\s*\(.*\);\s*$/m',
        ];

        $changed = false;
        foreach ($patterns as $pattern) {
            $new = $this->safeReplace($pattern, '', $content);
            if ($new !== null && $new !== $content) {
                $content = $new;
                $changed = true;
            }
        }

        if ($changed) {
            // Clean up blank lines left by removals (safely)
            $cleaned = $this->safeReplace("/\n{3,}/", "\n\n", $content);
            if ($cleaned !== null) {
                $content = $cleaned;
            }
        }

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
