<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

use CodeGuardian\Laravel\Support\CachedPhpParser;
use Illuminate\Support\Facades\File;

/**
 * Runs all built-in static analyzers against a set of files.
 * No API key, no internet, no cost — 100% embedded.
 *
 * Architecture notes:
 * - Analyzers are injected via constructor for testability.
 * - refactorFile() is decomposed into per-concern private methods:
 *     applyAutoFixes()   — deterministic code transformations
 *     applyInlineHints() — inline // CODEGUARDIAN-FIX: comments
 *     applyManualNotes() — [MANUAL] guidance messages
 * - analyze() accumulates findings without repeated array_merge (uses spread).
 * - writeTests() uses File facade for consistency with the rest of the codebase.
 */
class StaticOrchestrator
{
    private ArchitectureAnalyzer $architecture;
    private SecurityAnalyzer     $security;
    private PerformanceAnalyzer  $performance;
    private TechDebtAnalyzer     $techDebt;
    private StaticTestGenerator  $testGenerator;

    public function __construct(
        ?ArchitectureAnalyzer $architecture  = null,
        ?SecurityAnalyzer     $security      = null,
        ?PerformanceAnalyzer  $performance   = null,
        ?TechDebtAnalyzer     $techDebt      = null,
        ?StaticTestGenerator  $testGenerator = null,
    ) {
        $this->architecture  = $architecture  ?? new ArchitectureAnalyzer();
        $this->security      = $security      ?? new SecurityAnalyzer();
        $this->performance   = $performance   ?? new PerformanceAnalyzer();
        $this->techDebt      = $techDebt      ?? new TechDebtAnalyzer();
        $this->testGenerator = $testGenerator ?? new StaticTestGenerator();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Analysis
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Run full analysis on an array of files [$path => $content].
     *
     * @param  array<string,string> $files
     * @param  array<string,bool>   $options  Toggle individual analyzers on/off
     * @param  string               $scanPath  Used for reporting metadata only
     *
     * @return array{
     *   files_scanned: int,
     *   total_lines: int,
     *   overall_score: int,
     *   grade: string,
     *   agents: list<array>,
     *   all_findings: list<array>,
     *   summary: array,
     *   scan_path: string
     * }
     */
    public function analyze(array $files, array $options = [], string $scanPath = ''): array
    {
        $runArchitecture = $options['architecture'] ?? true;
        $runSecurity     = $options['security']     ?? true;
        $runPerformance  = $options['performance']  ?? true;
        $runTechDebt     = $options['tech_debt']    ?? true;

        // Run enabled analyzers and collect results
        $agentResults = [];
        if ($runArchitecture) {
            $agentResults[] = $this->architecture->analyze($files);
        }
        if ($runSecurity) {
            $agentResults[] = $this->security->analyze($files);
        }
        if ($runPerformance) {
            $agentResults[] = $this->performance->analyze($files);
        }
        if ($runTechDebt) {
            $agentResults[] = $this->techDebt->analyze($files);
        }

        // Flatten findings without repeated array_merge copies
        $allFindings = array_merge(...array_map(fn($r) => $r['findings'], $agentResults));

        $totalLines   = (int) array_sum(array_map(fn($c) => substr_count($c, "\n") + 1, $files));
        $overallScore = $this->calculateOverallScore($agentResults);
        $grade        = $this->scoreToGrade($overallScore);
        $summary      = $this->buildSummary($allFindings, $agentResults);

        return [
            'files_scanned' => count($files),
            'total_lines'   => $totalLines,
            'overall_score' => $overallScore,
            'grade'         => $grade,
            'agents'        => $agentResults,
            'all_findings'  => $allFindings,
            'summary'       => $summary,
            'scan_path'     => $scanPath,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test generation
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Generate test stubs for a set of PHP source files.
     *
     * @param  array<string,string> $files
     * @return list<GeneratedTest>
     */
    public function generateTests(array $files): array
    {
        $tests = [];

        foreach ($files as $filePath => $content) {
            if (! str_ends_with($filePath, '.php')) {
                continue;
            }
            if (! preg_match('/\bclass\s+\w+/', $content)) {
                continue;
            }
            // Skip test files themselves
            if (str_contains(strtolower($filePath), 'test')) {
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
     * Write generated test files to disk using File facade.
     *
     * @param  list<GeneratedTest> $tests
     * @return list<string>  written file paths
     */
    public function writeTests(array $tests, string $basePath): array
    {
        $written = [];

        foreach ($tests as $test) {
            $fullPath = $basePath . '/' . ltrim($test->filePath, '/');

            File::ensureDirectoryExists(dirname($fullPath));
            File::put($fullPath, $test->content);

            $written[] = $fullPath;
        }

        return $written;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Refactoring
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Apply deterministic, rule-based refactoring to a single file.
     *
     * Three phases:
     *   1. applyAutoFixes()   — safe code transformations (regex replacements)
     *   2. applyInlineHints() — // CODEGUARDIAN-FIX: comments at exact problem lines
     *   3. applyManualNotes() — [MANUAL] guidance messages (no code change)
     *
     * SAFETY CONTRACT
     * ───────────────
     * After Phase 1, the result is validated with PHP's built-in syntax checker
     * (php -l equivalent via PhpParser). If the result would produce invalid PHP,
     * the auto-fix is ROLLED BACK and the fix is downgraded to a [MANUAL] note.
     * This ensures we NEVER write syntactically broken PHP to disk.
     */
    public function refactorFile(string $filePath, string $content, array $findings): RefactorResult
    {
        // Deduplicate: keep first finding per category
        $findingsByCategory = [];
        foreach ($findings as $finding) {
            $cat = $finding['category'] ?? 'unknown';
            $findingsByCategory[$cat] ??= $finding;
        }

        $refactored = $content;
        $changes    = [];

        // Phase 1 — auto-fix with syntax safety gate
        [$refactored, $autoFixChanges] = $this->applyAutoFixesSafely($refactored, $content, $findingsByCategory);
        $changes = array_merge($changes, $autoFixChanges);

        // Phase 2 — inline hints (comments only, can never break syntax)
        [$refactored, $hintChanges] = $this->applyInlineHints($refactored, $findingsByCategory);
        $changes = array_merge($changes, $hintChanges);

        // Phase 3 — manual notes (no code change)
        $manualNotes = $this->applyManualNotes($findingsByCategory);
        $changes     = array_merge($changes, $manualNotes);

        return new RefactorResult(
            filePath:    $filePath,
            original:    $content,
            refactored:  $refactored,
            changes:     $changes,
            autoFixed:   count(array_filter($changes, fn($c) => str_starts_with($c, 'Auto-fixed:') || str_starts_with($c, 'Auto-commented:'))),
            manualTodos: count(array_filter($changes, fn($c) => str_starts_with($c, '[MANUAL]'))),
        );
    }

    /**
     * Check whether a PHP string is syntactically valid.
     * Uses CachedPhpParser — no subprocess, no shell exec.
     */
    public function isValidPhp(string $code): bool
    {
        return CachedPhpParser::parse($code) !== null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Phase 1 — Auto-fixes (deterministic code transformations)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Apply auto-fixes one at a time, with a PHP syntax check after each one.
     * If a fix produces invalid PHP it is rolled back and downgraded to [MANUAL].
     *
     * @param  string $originalContent  The untouched original (for rollback)
     * @return array{0: string, 1: list<string>}
     */
    private function applyAutoFixesSafely(
        string $content,
        string $originalContent,
        array  $findingsByCategory
    ): array {
        $changes = [];

        // ── Fix 1: mass_assignment ($request->all() → $request->validated()) ─
        if (isset($findingsByCategory['mass_assignment'])) {
            [$candidate, $fixed] = $this->fixMassAssignment($content);
            if ($fixed) {
                if ($this->isValidPhp($candidate)) {
                    $content   = $candidate;
                    $changes[] = 'Auto-fixed: replaced $request->all() with $request->validated()';
                } else {
                    $changes[] = '[MANUAL] mass_assignment: auto-fix would produce invalid PHP — replace $request->all() with $request->validated() manually';
                }
            }
        }

        // ── Fix 2: debug_code (remove dd/dump/var_dump/print_r) ──────────────
        if (isset($findingsByCategory['debug_code'])) {
            [$candidate, $fixed] = $this->removeDebugCode($content);
            if ($fixed) {
                if ($this->isValidPhp($candidate)) {
                    $content   = $candidate;
                    $changes[] = 'Auto-fixed: removed debug statements (dd, dump, var_dump)';
                } else {
                    $changes[] = '[MANUAL] debug_code: auto-fix would produce invalid PHP — remove dd()/dump() calls manually';
                }
            }
        }

        // ── Fix 3: select_all — NOT auto-replaced ─────────────────────────────
        // Model::all() → paginate(25) is context-dependent:
        //   • Safe   in a controller returning a paginated resource
        //   • UNSAFE if the result is chained with ->first(), ->count(), ->isEmpty(),
        //     ->sum(), ->pluck(), etc. (LengthAwarePaginator != Collection)
        //   • UNSAFE if the variable is passed to something expecting a Collection
        // We ALWAYS downgrade this to an inline hint so the developer reviews it.
        if (isset($findingsByCategory['select_all'])) {
            $changes[] = '[MANUAL] select_all: replace Model::all() with Model::paginate(25) '
                       . '— verify callers accept a Paginator (not a Collection) before applying';
        }

        return [$content, $changes];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Phase 2 — Inline hints (// CODEGUARDIAN-FIX: comments at exact lines)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: list<string>}  [modified content, change messages]
     */
    private function applyInlineHints(string $content, array $findingsByCategory): array
    {
        $changes = [];

        $inlineHintMap = [
            'n_plus_one'      => fn($f) => "N+1 QUERY: Add eager loading above.\n     Replace: ->get()  →  ->with(['relationName'])->get()",
            'eager_loading'   => fn($f) => "N+1 QUERY: Add eager loading above.\n     Replace: ->get()  →  ->with(['relationName'])->get()",
            'inefficient_count' => fn($f) => "PERFORMANCE: count() loads all records into memory.\n     Replace: count(\$collection)  →  Model::where(...)->count()",
            'memory_usage'    => fn($f) => "MEMORY: ::all() in bulk operation loads every record at once.\n     Replace with: Model::chunk(500, function(\$items) { ... })",
            'sql_injection'   => function ($f) {
                $snippet = $f['code_snippet'] ?? '...';
                return "SQL INJECTION: Replace string concatenation with parameterized bindings.\n" .
                       "     BEFORE: {$snippet}\n" .
                       "     AFTER:  DB::select('SELECT ... WHERE id = ?', [\$id])";
            },
        ];

        foreach ($inlineHintMap as $cat => $messageFn) {
            if (! isset($findingsByCategory[$cat])) {
                continue;
            }

            $finding = $findingsByCategory[$cat];
            $line    = $finding['line_start'] ?? 0;

            [$content, $inserted] = $this->insertInlineFixComment($content, $line, $messageFn($finding));
            if ($inserted) {
                $changes[] = "Auto-commented: {$cat} at line {$line} — see inline CODEGUARDIAN-FIX comment";
            } else {
                $changes[] = "[MANUAL] {$cat}: " . ($finding['recommendation'] ?? 'Review manually');
            }
        }

        return [$content, $changes];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Phase 3 — Manual notes (guidance only, no code change)
    // ──────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function applyManualNotes(array $findingsByCategory): array
    {
        $notes = [];

        // Architectural
        $notes += $this->manualNote($findingsByCategory, 'fat_controller',
            '[MANUAL] Fat controller — extract business logic to a dedicated Service class');
        $notes += $this->manualNote($findingsByCategory, 'service_layer',
            '[MANUAL] Missing service layer — create a Service class and inject it via constructor');
        $notes += $this->manualNote($findingsByCategory, 'solid',
            '[MANUAL] SOLID violation — extract inline validation to a FormRequest class');
        $notes += $this->manualNote($findingsByCategory, 'fat_model',
            '[MANUAL] Fat model — extract business methods to a Service class');
        $notes += $this->manualNote($findingsByCategory, 'dependency_injection',
            '[MANUAL] Heavy static facade use — inject dependencies via constructor instead');

        // Security
        $notes += $this->manualNote($findingsByCategory, 'secret_exposure',
            '[MANUAL] Hardcoded secret — move to .env and access via env() or config()');
        $notes += $this->manualNote($findingsByCategory, 'authorization',
            '[MANUAL] Missing authorization — add $this->authorize() or use a Policy class');
        $notes += $this->manualNote($findingsByCategory, 'xss',
            '[MANUAL] Unescaped output {!! !!} — use {{ }} unless HTML is explicitly sanitized');
        $notes += $this->manualNote($findingsByCategory, 'insecure_upload',
            "[MANUAL] File upload lacks MIME validation — add: 'file' => 'required|file|mimes:jpg,png,pdf|max:10240'");

        // Performance
        $notes += $this->manualNote($findingsByCategory, 'missing_cache',
            "[MANUAL] Complex query without caching — wrap in Cache::remember('key', 3600, fn() => ...)");
        $notes += $this->manualNote($findingsByCategory, 'missing_index',
            "[MANUAL] Potential missing DB index — add \$table->index('field') in your migration");

        // Tech debt
        $notes += $this->manualNote($findingsByCategory, 'large_class',
            '[MANUAL] Large class — split into focused classes by responsibility');
        $notes += $this->manualNote($findingsByCategory, 'high_complexity',
            '[MANUAL] High cyclomatic complexity — break method into smaller focused methods');
        $notes += $this->manualNote($findingsByCategory, 'deep_nesting',
            '[MANUAL] Deep nesting — use early returns (guard clauses) to flatten logic');
        $notes += $this->manualNoteCounted($findingsByCategory, 'missing_types',
            fn(int $n) => "[MANUAL] Missing return type declarations — add PHP 8.1 types to {$n} method(s)");
        $notes += $this->manualNote($findingsByCategory, 'magic_numbers',
            '[MANUAL] Magic numbers — extract to named constants (const MAX_ATTEMPTS = 5)');
        $notes += $this->manualNoteCounted($findingsByCategory, 'todo_debt',
            fn(int $n) => "[MANUAL] {$n} TODO/FIXME comment(s) — create tickets and resolve before release");
        $notes += $this->manualNote($findingsByCategory, 'dead_code',
            '[MANUAL] Commented-out dead code — delete it (git history preserves it)');
        $notes += $this->manualNote($findingsByCategory, 'duplication',
            '[MANUAL] Duplicated code block — extract to a shared Trait, Service, or Base class');

        // Unknown / catch-all
        foreach ($findingsByCategory as $cat => $finding) {
            // Already handled above — skip
            $knownCats = [
                'mass_assignment','debug_code','select_all','n_plus_one','eager_loading',
                'inefficient_count','memory_usage','sql_injection',
                'fat_controller','service_layer','solid','fat_model','dependency_injection',
                'secret_exposure','authorization','xss','insecure_upload',
                'missing_cache','missing_index',
                'large_class','high_complexity','deep_nesting','missing_types',
                'magic_numbers','todo_debt','dead_code','duplication',
            ];
            if (in_array($cat, $knownCats, true)) {
                continue;
            }
            $label   = ucwords(str_replace('_', ' ', $cat));
            $rec     = $finding['recommendation'] ?? "Review and fix {$label} manually.";
            $notes[] = "[MANUAL] {$label}: {$rec}";
        }

        return $notes;
    }

    /** Return a [MANUAL] note for $cat if it's in $findingsByCategory, else []. */
    private function manualNote(array $findingsByCategory, string $cat, string $message): array
    {
        return isset($findingsByCategory[$cat]) ? [$message] : [];
    }

    /** Like manualNote but the message is built from the count of findings for that category. */
    private function manualNoteCounted(array $findingsByCategory, string $cat, callable $messageFn): array
    {
        if (! isset($findingsByCategory[$cat])) {
            return [];
        }
        // For counted notes we need the raw findings count — the caller passes the deduplicated map
        // so we just use 1 as the count unless extra context is available via 'count' key
        $count = $findingsByCategory[$cat]['_count'] ?? 1;
        return [$messageFn($count)];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Deterministic auto-fix helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Apply a regex replacement safely.
     * Returns null on PCRE error — callers MUST check before writing to disk.
     */
    private function safeReplace(string $pattern, string $replacement, string $content): ?string
    {
        $result = preg_replace($pattern, $replacement, $content);
        return ($result === null) ? null : $result;
    }


    /**
     * Insert a // CODEGUARDIAN-FIX: comment block directly above the target line.
     * Preserves indentation; does NOT change any executable code.
     *
     * @return array{0: string, 1: bool}
     */
    private function insertInlineFixComment(string $content, int $lineNumber, string $message): array
    {
        if ($lineNumber <= 0) {
            return [$content, false];
        }

        $lines = explode("\n", $content);
        $idx   = $lineNumber - 1; // 0-based

        if (! isset($lines[$idx])) {
            return [$content, false];
        }

        preg_match('/^(\s*)/', $lines[$idx], $m);
        $indent = $m[1] ?? '';

        $commentLines = array_map(
            fn($l) => $indent . '// CODEGUARDIAN-FIX: ' . $l,
            explode("\n", $message)
        );

        array_splice($lines, $idx, 0, $commentLines);

        return [implode("\n", $lines), true];
    }

    /**
     * Replace $request->all() with $request->validated() in the three safe contexts:
     *   Model::create($request->all())          → Model::create($request->validated())
     *   $model->update($request->all())         → $model->update($request->validated())
     *   $model->fill($request->all())           → $model->fill($request->validated())
     *
     * Patterns are written to FULLY match the $request->all() call INCLUDING its closing
     * parenthesis, then replace the entire expression so no dangling '(' is left behind.
     *
     * Edge cases handled:
     *   - Whitespace between tokens (e.g. create( $request -> all() ))
     *   - Extra arguments after $request->all() for ->update()
     *     e.g. ->update($request->all(), ['timestamps' => false])
     *     → ->update($request->validated(), ['timestamps' => false])
     *
     * @return array{0: string, 1: bool}
     */
    private function fixMassAssignment(string $content): array
    {
        // Pattern explanation:
        //   ::create\s*\(         — matches ::create(
        //   \s*\$request\s*->\s*  — $request ->
        //   all\s*\(\s*\)         — all()
        //   \s*\)                 — the closing ) of create()
        //
        // For ->update and ->fill we DON'T match the outer closing ')' because
        // there may be additional arguments after $request->all().
        // Instead we only replace $request->all() → $request->validated() on those lines.

        $patterns = [
            // ::create($request->all()) — replace entire expression
            '/::create\s*\(\s*\$request\s*->\s*all\s*\(\s*\)\s*\)/' => '::create($request->validated())',

            // ->update($request->all()  — replace only $request->all() part, preserve rest of args
            '/->update\s*\(\s*\$request\s*->\s*all\s*\(\s*\)/' => '->update($request->validated()',

            // ->fill($request->all()) — replace entire expression
            '/->fill\s*\(\s*\$request\s*->\s*all\s*\(\s*\)\s*\)/' => '->fill($request->validated())',
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

    /** @return array{0: string, 1: bool} */
    private function removeDebugCode(string $content): array
    {
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
        $bySeverity = [
            Severity::CRITICAL => 0,
            Severity::HIGH     => 0,
            Severity::MEDIUM   => 0,
            Severity::LOW      => 0,
        ];
        $byCategory = [];
        $byFile     = [];

        foreach ($allFindings as $f) {
            $sev = Severity::clamp($f['severity'] ?? '');
            $bySeverity[$sev]++;

            $byCategory[$f['category']] = ($byCategory[$f['category']] ?? 0) + 1;

            $fileKey          = basename($f['file'] ?? '');
            $byFile[$fileKey] = ($byFile[$fileKey] ?? 0) + 1;
        }

        arsort($byCategory);
        arsort($byFile);

        // Sort findings by severity so top-5 are the most critical
        usort($allFindings, fn($a, $b) =>
            (Severity::ORDER[Severity::clamp($a['severity'])] ?? 4) <=>
            (Severity::ORDER[Severity::clamp($b['severity'])] ?? 4)
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
