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
    public function analyze(array $files, array $options = [], string $scanPath = '', ?callable $onProgress = null): array
    {
        $runArchitecture = $options['architecture'] ?? true;
        $runSecurity     = $options['security']     ?? true;
        $runPerformance  = $options['performance']  ?? true;
        $runTechDebt     = $options['tech_debt']    ?? true;

        // Run enabled analyzers and collect results, emitting progress events.
        $agentResults = [];
        if ($runArchitecture) {
            $agentResults[] = $this->runAnalyzer($this->architecture, 'architect', $files, $onProgress);
        }
        if ($runSecurity) {
            $agentResults[] = $this->runAnalyzer($this->security, 'security', $files, $onProgress);
        }
        if ($runPerformance) {
            $agentResults[] = $this->runAnalyzer($this->performance, 'performance', $files, $onProgress);
        }
        if ($runTechDebt) {
            $agentResults[] = $this->runAnalyzer($this->techDebt, 'tech_debt', $files, $onProgress);
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

    /**
     * Run a single analyzer, forwarding per-file ticks and emitting
     * stage_start / stage_end progress events with timing + finding count.
     */
    private function runAnalyzer(BaseAnalyzer $analyzer, string $stage, array $files, ?callable $onProgress): array
    {
        if ($onProgress !== null) {
            $onProgress('stage_start', ['stage' => $stage]);
        }

        $started = microtime(true);
        $onFile  = $onProgress === null
            ? null
            : static fn(string $file) => $onProgress('file', ['stage' => $stage, 'file' => $file]);

        $result = $analyzer->analyze($files, $onFile);

        if ($onProgress !== null) {
            $onProgress('stage_end', [
                'stage'      => $stage,
                'findings'   => count($result['findings'] ?? []),
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }

        return $result;
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
        // Build category map — keep ALL findings per category (not just first)
        // so multi-occurrence fixes (N+1 on different lines) can all be applied.
        $findingsByCategory = [];
        foreach ($findings as $finding) {
            $cat = $finding['category'] ?? 'unknown';
            $findingsByCategory[$cat][] = $finding;
        }
        // Flatten to single finding per category for the hint/note phases
        $singleByCategory = array_map(fn($list) => $list[0], $findingsByCategory);

        $refactored     = $content;
        $changes        = [];
        $generatedFiles = [];

        // Phase 1 — actual code transformations (with PHP syntax safety gate)
        [$refactored, $autoFixChanges, $generatedFiles] = $this->applyAutoFixesSafely(
            $refactored, $content, $findingsByCategory, $filePath
        );
        $changes = array_merge($changes, $autoFixChanges);

        // Phase 2 — manual guidance notes for what CANNOT be auto-fixed
        $manualNotes = $this->applyManualNotes($singleByCategory, $autoFixChanges);
        $changes     = array_merge($changes, $manualNotes);

        return new RefactorResult(
            filePath:       $filePath,
            original:       $content,
            refactored:     $refactored,
            changes:        $changes,
            autoFixed:      count(array_filter($changes, fn($c) => str_starts_with($c, 'Auto-fixed'))),
            manualTodos:    count(array_filter($changes, fn($c) => str_starts_with($c, '[MANUAL]'))),
            generatedFiles: $generatedFiles,
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
    // Phase 1 — Actual code transformations
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Apply every applicable fix in order. Each fix is syntax-checked before
     * it is accepted; failed fixes are rolled back to the pre-fix content so
     * one bad transformation never cascades into the next.
     *
     * Returns [modifiedContent, changeMessages, generatedFiles].
     *
     * @param  array<string,list<array>> $findingsByCategory  ALL findings per cat
     * @return array{0:string, 1:list<string>, 2:array<string,string>}
     */
    private function applyAutoFixesSafely(
        string $content,
        string $originalContent,
        array  $findingsByCategory,
        string $filePath = ''
    ): array {
        $changes        = [];
        $generatedFiles = [];

        /**
         * Safe-apply helper: runs $fixFn($content), validates PHP, accepts or
         * reverts. For Blade files ($isBlade=true) PHP validation is skipped.
         *
         * Change messages are prefixed with "[{$cat}]" so that applyManualNotes()
         * can reliably detect which categories were already auto-fixed and skip
         * emitting a duplicate [MANUAL] note for them.
         */
        $isBlade = str_ends_with($filePath, '.blade.php');
        $apply   = function (string $cat, callable $fixFn, string $successMsg, string $failMsg)
                   use (&$content, &$changes, $isBlade): void {
            [$candidate, $fixed] = $fixFn($content);
            if (! $fixed) {
                return;
            }
            if ($isBlade || $this->isValidPhp($candidate)) {
                $content   = $candidate;
                $changes[] = "Auto-fixed [{$cat}]: {$successMsg}";
            } else {
                $changes[] = "[MANUAL] {$cat}: {$failMsg}";
            }
        };

        // ── 1. Mass assignment: $request->all() → $request->validated() ──────
        if (isset($findingsByCategory['mass_assignment'])) {
            $apply('mass_assignment',
                fn($c) => $this->fixMassAssignment($c),
                'replaced $request->all() with $request->validated()',
                'replace $request->all() with $request->validated() — use validated() to ensure only declared inputs are accepted'
            );
        }

        // ── 2. Debug code: remove dd/dump/var_dump/print_r ───────────────────
        if (isset($findingsByCategory['debug_code'])) {
            $apply('debug_code',
                fn($c) => $this->removeDebugCode($c),
                'removed debug statements (dd, dump, var_dump)',
                'remove dd()/dump()/var_dump() calls before deploying to production'
            );
        }

        // ── 3. SQL injection: parameterize raw DB queries ─────────────────────
        if (isset($findingsByCategory['sql_injection'])) {
            $apply('sql_injection',
                fn($c) => $this->fixSqlInjection($c),
                'SQL injection fixed — replaced variable interpolation in DB queries with ? parameterized bindings',
                'replace "$var" inside query strings with "?" and pass [$var] as the bindings array e.g. DB::select("SELECT ... WHERE id = ?", [$id])'
            );
        }

        // ── 4. XSS: {!! $var !!} → {{ $var }} in Blade ───────────────────────
        if (isset($findingsByCategory['xss'])) {
            $apply('xss',
                fn($c) => $this->fixXss($c),
                'XSS fixed — replaced unescaped {!! !!} output with escaped {{ }} in Blade templates',
                'replace {!! $var !!} with {{ $var }} — only use {!! !!} for pre-sanitized HTML'
            );
        }

        // ── 5. Hardcoded secrets → env() references ───────────────────────────
        if (isset($findingsByCategory['secret_exposure'])) {
            $apply('secret_exposure',
                fn($c) => $this->fixHardcodedSecrets($c),
                'hardcoded credentials replaced with env() references — add the real values to your .env file',
                'move hardcoded passwords/keys/tokens to .env and reference with env(\'KEY\') or config(\'app.key\')'
            );
        }

        // ── 6. select_all: Model::all() → Model::paginate(25) ─────────────────
        if (isset($findingsByCategory['select_all'])) {
            $apply('select_all',
                fn($c) => $this->fixSelectAll($c),
                'Model::all() replaced with paginate(25) — adjust page size in codeguardian config if needed',
                'replace Model::all() with Model::paginate(25) — verify callers accept a LengthAwarePaginator, not a Collection'
            );
        }

        // ── 7. N+1 queries: add ->with() eager loading ────────────────────────
        if (isset($findingsByCategory['n_plus_one'])) {
            foreach ($findingsByCategory['n_plus_one'] as $finding) {
                $apply('n_plus_one',
                    fn($c) => $this->fixNPlusOne($c, $finding),
                    'N+1 query fixed — added eager loading with ->with() for detected relationship',
                    "add ->with('relationName') before ->get() to avoid N+1 queries — detected in: " . ($finding['code_snippet'] ?? '')
                );
            }
        }

        // ── 8. Missing authorization: add $this->authorize() stubs ────────────
        if (isset($findingsByCategory['authorization'])) {
            $apply('authorization',
                fn($c) => $this->fixMissingAuthorization($c),
                'authorization stubs added — $this->authorize() inserted in store/update/destroy methods (update with your Policy)',
                'add $this->authorize(\'action\', Model::class) to store/update/destroy — or use a Policy class'
            );
        }

        // ── 9. Inefficient count: count(Model::all()) → Model::count() ────────
        if (isset($findingsByCategory['inefficient_count'])) {
            $apply('inefficient_count',
                fn($c) => $this->fixInefficientCount($c),
                'inefficient count fixed — replaced count(Model::all()) with Model::count() (DB-level count, no memory overhead)',
                'replace count($model::all()) with Model::count() to run a COUNT(*) SQL query instead of loading all rows'
            );
        }

        // ── 10. Missing return types: add PHP 8.1 type declarations ────────────
        if (isset($findingsByCategory['missing_types'])) {
            $apply('missing_types',
                fn($c) => $this->fixMissingReturnTypes($c),
                'PHP 8.1 return type declarations added to methods with inferable types (bool/int/void/string)',
                'add return type declarations to public/protected methods — start with easy ones: bool for is*/has*, void for handle/setUp'
            );
        }

        // ── 11. Inline validation → FormRequest class generation ─────────────
        if (isset($findingsByCategory['solid']) || isset($findingsByCategory['fat_controller'])) {
            $generated = $this->generateFormRequest($content, $filePath);
            if ($generated !== null) {
                [$newContent, $requestFile, $requestClass] = $generated;
                if ($this->isValidPhp($newContent) && $this->isValidPhp($requestFile['content'])) {
                    $content                              = $newContent;
                    $generatedFiles[$requestFile['path']] = $requestFile['content'];
                    $changes[] = "Auto-fixed: extracted inline validation into {$requestClass} FormRequest — new file: {$requestFile['path']}";
                } else {
                    $changes[] = '[MANUAL] solid: extract $request->validate([...]) into a dedicated FormRequest class in app/Http/Requests/';
                }
            }
        }

        return [$content, $changes, $generatedFiles];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Phase 2 — Manual guidance (only for issues not already auto-fixed)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Emit [MANUAL] guidance notes for issues that could not be deterministically
     * auto-fixed. Skip any category that was already handled in Phase 1.
     *
     * @param  array<string,array>  $singleByCategory  one finding per category
     * @param  list<string>         $alreadyFixed      change messages from Phase 1
     * @return list<string>
     */
    private function applyManualNotes(array $singleByCategory, array $alreadyFixed = []): array
    {
        // Build a set of categories already handled so we don't double-report.
        // Messages are formatted as "Auto-fixed [category]: ..." — extract the
        // bracketed category name so we can skip emitting a duplicate [MANUAL] note.
        $handled = [];
        foreach ($alreadyFixed as $msg) {
            if (preg_match('/^Auto-fixed \[(\w+)\]/', $msg, $m)) {
                $handled[] = strtolower($m[1]);
            }
        }

        $note = function (string $cat, string $msg) use ($singleByCategory, $handled): array {
            if (! isset($singleByCategory[$cat]) || in_array($cat, $handled, true)) {
                return [];
            }
            return ["[MANUAL] {$msg}"];
        };

        $notes = [];

        // Architecture
        $notes = array_merge($notes,
            $note('fat_controller',       'Fat controller — extract business logic to app/Services/{Name}Service.php, inject via constructor'),
            $note('service_layer',        'Missing service layer — create a Service class for DB + business logic, keep controller thin'),
            $note('solid',                'SOLID: extract $request->validate([...]) into a dedicated FormRequest in app/Http/Requests/'),
            $note('fat_model',            'Fat model — move business methods to a Service or Action class'),
            $note('dependency_injection', 'Heavy facade use — inject dependencies via constructor (easier to test and mock)'),
            $note('long_method',          'Long method — break into smaller private methods, each doing one thing'),
        );

        // Security
        $notes = array_merge($notes,
            $note('secret_exposure',  'Hardcoded secret — move value to .env, reference with env(\'KEY\') or config(\'app.key\')'),
            $note('authorization',    'Missing authorization — add $this->authorize(\'action\', Model::class) in store/update/destroy'),
            $note('xss',              'XSS: replace {!! $var !!} with {{ $var }} — only use raw output for pre-sanitized HTML'),
            $note('insecure_upload',  "File upload: add MIME + size validation: 'file' => 'required|file|mimes:jpg,png,pdf|max:10240'"),
            $note('sql_injection',    'SQL injection: replace DB::select("...{$var}...") with parameterized DB::select("...?...", [$var])'),
        );

        // Performance
        $notes = array_merge($notes,
            $note('n_plus_one',         'N+1 query — add ->with([\'relation\']) before ->get() to eager load in a single JOIN'),
            $note('select_all',         'Model::all() — replace with Model::paginate(25) or add a ->where() scope to limit results'),
            $note('inefficient_count',  'count(Model::all()) — replace with Model::count() (runs COUNT(*), no memory overhead)'),
            $note('missing_cache',      'Complex query — wrap in Cache::remember(\'key\', 3600, fn() => ...) to avoid repeated DB hits'),
            $note('missing_index',      'Potential missing DB index — add $table->index(\'field\') in a new migration'),
            $note('memory_usage',       'Bulk loop over ::all() — replace with Model::chunk(500, fn($batch) => ...) to avoid OOM'),
        );

        // Tech debt
        $notes = array_merge($notes,
            $note('large_class',     'Large class — split by responsibility (SRP); aim for < 200 lines per class'),
            $note('high_complexity', 'High complexity — break method into smaller focused methods; aim for < 10 branches'),
            $note('deep_nesting',    'Deep nesting — use early returns (guard clauses) to flatten logic: if (!$x) return; ...'),
            $note('missing_types',   'Missing return types — add PHP 8.1 types: bool for is*/has*, void for handlers, int for counts'),
            $note('magic_numbers',   'Magic numbers — extract to named constants: const MAX_ATTEMPTS = 5; const PAGE_SIZE = 25;'),
            $note('dead_code',       'Commented-out code — delete it; git history preserves every deleted line'),
            $note('duplication',     'Duplicated block — extract to a shared Trait, Service, or Base class'),
        );

        if (isset($singleByCategory['todo_debt'])) {
            $count = $singleByCategory['todo_debt']['_count'] ?? 1;
            $notes[] = "[MANUAL] {$count} TODO/FIXME comment(s) — create tickets for each and remove the comment when resolved";
        }

        // Catch-all for unknown categories
        $knownCats = [
            'mass_assignment','debug_code','select_all','n_plus_one','eager_loading',
            'inefficient_count','memory_usage','sql_injection','fat_controller',
            'service_layer','solid','fat_model','dependency_injection','secret_exposure',
            'authorization','xss','insecure_upload','missing_cache','missing_index',
            'large_class','high_complexity','deep_nesting','missing_types','magic_numbers',
            'todo_debt','dead_code','duplication','long_method',
        ];
        foreach ($singleByCategory as $cat => $finding) {
            if (in_array($cat, $knownCats, true)) {
                continue;
            }
            $label   = ucwords(str_replace('_', ' ', $cat));
            $rec     = $finding['recommendation'] ?? "Review and refactor {$label}.";
            $notes[] = "[MANUAL] {$label}: {$rec}";
        }

        return $notes;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Auto-fix helpers — each returns [modifiedContent, bool $changed]
    // ──────────────────────────────────────────────────────────────────────────

    private function safeReplace(string $pattern, string $replacement, string $content): ?string
    {
        $result = preg_replace($pattern, $replacement, $content);
        return ($result === null) ? null : $result;
    }

    /** $request->all() → $request->validated() in create/update/fill calls */
    private function fixMassAssignment(string $content): array
    {
        $patterns = [
            '/::create\s*\(\s*\$request\s*->\s*all\s*\(\s*\)\s*\)/' => '::create($request->validated())',
            '/->update\s*\(\s*\$request\s*->\s*all\s*\(\s*\)/'       => '->update($request->validated()',
            '/->fill\s*\(\s*\$request\s*->\s*all\s*\(\s*\)\s*\)/'    => '->fill($request->validated())',
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

    /** Remove dd/dump/var_dump/print_r statements */
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
            $content = $cleaned ?? $content;
        }

        return [$content, $changed];
    }

    /**
     * Parameterize raw DB queries:
     *   DB::select("SELECT * FROM users WHERE id = $id")
     *   → DB::select("SELECT * FROM users WHERE id = ?", [$id])
     */
    private function fixSqlInjection(string $content): array
    {
        $changed = false;

        $result = preg_replace_callback(
            '/\b(DB::(?:select|statement|update|delete|insert|affectingStatement))\s*\(\s*"([^"]*\$\{?\w+\}?[^"]*)"\s*\)/',
            function (array $m) use (&$changed): string {
                $method = $m[1];
                $query  = $m[2];

                preg_match_all('/\$\{?(\w+)\}?/', $query, $varMatches);
                $vars = $varMatches[1] ?? [];
                if (empty($vars)) {
                    return $m[0];
                }

                $paramQuery = preg_replace('/\$\{?\w+\}?/', '?', $query) ?? $query;
                $bindings   = implode(', $', $vars);

                $changed = true;
                return "{$method}(\"{$paramQuery}\", [\${$bindings}])";
            },
            $content
        );

        if ($result === null) {
            return [$content, false];
        }

        return [$result, $changed];
    }

    /**
     * XSS: {!! $var !!} → {{ $var }} in Blade templates.
     * Only replaces simple variable output — does NOT touch expressions
     * with explicit e() or htmlspecialchars() wrappers.
     */
    private function fixXss(string $content): array
    {
        // Replace {!! $var !!}, {!! $obj->prop !!}, {!! $arr['key'] !!}
        $result = preg_replace(
            '/\{!!\s*(\$[\w\->\[\]\'\"\.]+(?:\(\))?)\s*!!\}/',
            '{{ $1 }}',
            $content
        );

        if ($result === null || $result === $content) {
            return [$content, false];
        }

        return [$result, true];
    }

    /**
     * Hardcoded secrets → env() references.
     * Matches common variable names: $password, $secret, $apiKey, $token, $key.
     * Replaces the literal value with env('VARNAME') and appends a TODO comment.
     */
    private function fixHardcodedSecrets(string $content): array
    {
        $changed = false;

        $result = preg_replace_callback(
            '/\$(password|secret|api_key|apikey|api_secret|token|auth_key|private_key)\s*=\s*([\'"])([^\'"]{4,})\2/i',
            function (array $m) use (&$changed): string {
                $var    = $m[1];
                $envKey = 'APP_' . strtoupper(preg_replace('/([A-Z])/', '_$1', $var));
                $changed = true;
                return "\${$var} = env('{$envKey}') /* TODO: add {$envKey}=... to your .env file */";
            },
            $content
        );

        if ($result === null) {
            return [$content, false];
        }

        return [$result, $changed];
    }

    /**
     * Model::all() → Model::paginate(25).
     * Skips calls immediately chained with collection-only methods to avoid
     * breaking callers that expect a Collection not a LengthAwarePaginator.
     */
    private function fixSelectAll(string $content): array
    {
        $unsafeChains = 'first|last|count|isEmpty|isNotEmpty|sum|avg|min|max'
            . '|pluck|toArray|toJson|flatten|chunk|slice|splice|forget|sortBy|unique|groupBy|keyBy';

        $changed = false;

        $result = preg_replace_callback(
            '/([A-Z]\w+)::all\s*\(\s*\)/',
            function (array $m) use ($content, $unsafeChains, &$changed): string {
                // Look at what follows this call in the source
                $pos   = strpos($content, $m[0]);
                $after = ($pos !== false) ? substr($content, $pos + strlen($m[0]), 60) : '';
                if (preg_match('/^\s*->(?:' . $unsafeChains . ')\s*\(/', $after)) {
                    return $m[0]; // unsafe chain — leave alone
                }
                $changed = true;
                return $m[1] . '::paginate(25)';
            },
            $content
        );

        if ($result === null) {
            return [$content, false];
        }

        return [$result, $changed];
    }

    /**
     * N+1: add ->with('relation') before ->get() / ->all() calls.
     * The relationship name is extracted from the finding's code_snippet,
     * e.g. "$order->user->name" → 'user'.
     */
    private function fixNPlusOne(string $content, array $finding): array
    {
        $snippet = $finding['code_snippet'] ?? '';
        preg_match('/\$\w+->(\w+)->\w+/', $snippet, $m);
        $relation = $m[1] ?? null;

        if ($relation === null || in_array($relation, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            return [$content, false];
        }

        $changed = false;
        $result  = preg_replace_callback(
            '/(->\s*(?:get|all)\s*\(\s*\))/',
            function (array $m) use ($relation, &$changed): string {
                // Only add with() once per relationship
                $changed = true;
                return "->with('{$relation}'){$m[1]}";
            },
            $content,
            1  // limit to first occurrence
        );

        if ($result === null) {
            return [$content, false];
        }

        return [$result, $changed];
    }

    /**
     * Missing authorization: insert $this->authorize() stubs in store/update/destroy
     * methods that have no authorization check.
     */
    private function fixMissingAuthorization(string $content): array
    {
        $changed = false;

        $result = preg_replace_callback(
            '/public\s+function\s+(store|update|destroy)\s*\(([^)]*)\)\s*\{(?![\s\S]{0,200}\$this->authorize)/m',
            function (array $m) use (&$changed): string {
                $ability = match ($m[1]) {
                    'store'   => 'create',
                    'update'  => 'update',
                    'destroy' => 'delete',
                    default   => $m[1],
                };
                $changed = true;
                // Preserve indentation from the opening brace line
                return "public function {$m[1]}({$m[2]})\n    {\n        \$this->authorize('{$ability}'); // TODO: pass the model instance e.g. authorize('{$ability}', \$model)";
            },
            $content
        );

        if ($result === null) {
            return [$content, false];
        }

        return [$result, $changed];
    }

    /**
     * Inefficient count:
     *   count(Model::all())    → Model::count()
     *   count($query->get())   → $query->count()
     */
    private function fixInefficientCount(string $content): array
    {
        $changed = false;

        // count(Model::all()) → Model::count()
        $r1 = preg_replace_callback(
            '/count\s*\(\s*([A-Z]\w+)::all\s*\(\s*\)\s*\)/',
            function (array $m) use (&$changed): string {
                $changed = true;
                return $m[1] . '::count()';
            },
            $content
        );
        if ($r1 !== null) {
            $content = $r1;
        }

        // count($var->get()) → $var->count()
        $r2 = preg_replace_callback(
            '/count\s*\(\s*(\$\w+(?:->\w+\(\))*)->get\s*\(\s*\)\s*\)/',
            function (array $m) use (&$changed): string {
                $changed = true;
                return $m[1] . '->count()';
            },
            $content
        );
        if ($r2 !== null) {
            $content = $r2;
        }

        return [$content, $changed];
    }

    /**
     * Add PHP 8.1 return type declarations to public/protected methods with
     * inferable types (bool for is* / has*, void for handle/register/setUp).
     * Methods already typed or with complex/ambiguous return types are skipped.
     */
    private function fixMissingReturnTypes(string $content): array
    {
        $changed = false;

        $result = preg_replace_callback(
            '/(public|protected)\s+function\s+(\w+)\s*\(([^)]*)\)\s*(?!:)\s*\{/',
            function (array $m) use (&$changed): string {
                $visibility = $m[1];
                $name       = $m[2];
                $params     = $m[3];

                // Skip constructors, magic methods, and test methods
                if (str_starts_with($name, '__')
                    || str_starts_with($name, 'test')
                    || str_starts_with($name, 'it_')) {
                    return $m[0];
                }

                $type = $this->inferReturnType($name);
                if ($type === null) {
                    return $m[0];
                }

                $changed = true;
                return "{$visibility} function {$name}({$params}): {$type} {";
            },
            $content
        );

        if ($result === null) {
            return [$content, false];
        }

        return [$result, $changed];
    }

    /** Infer a PHP return type from a method name. Returns null when ambiguous. */
    private function inferReturnType(string $name): ?string
    {
        $lc = strtolower($name);

        if (preg_match('/^(is|has|can|should|was|will|did|allows|needs)\w*/i', $name)) {
            return 'bool';
        }
        if (in_array($lc, ['handle', 'register', 'boot', 'setup', 'teardown', 'run', 'execute', 'fire'], true)) {
            return 'void';
        }
        if (preg_match('/^(count|total|sum|size|length)\w*/i', $name)) {
            return 'int';
        }
        if (preg_match('/^(toString|render|toHtml|format)\w*/i', $name)) {
            return 'string';
        }

        return null; // too ambiguous — don't add a wrong type
    }

    /**
     * Extract inline $request->validate([...]) into a FormRequest class.
     * Returns [$updatedControllerContent, ['path'=>..., 'content'=>...], $className]
     * or null if no extractable validation block is found.
     */
    private function generateFormRequest(string $content, string $filePath): ?array
    {
        // Find a $request->validate([...]) block
        if (! preg_match(
            '/(\$\w+\s*=\s*)?\$request->validate\s*\(\s*(\[[^\]]{20,}\])\s*\)/s',
            $content,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $rulesArray = $m[2][0];
        $fullMatch  = $m[0][0];

        // Derive class name from controller file name
        $baseName    = basename($filePath, '.php');
        $baseName    = preg_replace('/Controller$/', '', $baseName) ?? $baseName;
        $className   = $baseName . 'Request';
        $namespace   = 'App\\Http\\Requests';
        $requestPath = 'app/Http/Requests/' . $className . '.php';

        $requestContent = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Illuminate\Foundation\Http\FormRequest;

        class {$className} extends FormRequest
        {
            public function authorize(): bool
            {
                return true; // TODO: add authorization logic
            }

            public function rules(): array
            {
                return {$rulesArray};
            }
        }
        PHP;

        // Replace the validate() call in the controller with the FormRequest type-hint
        // (update the method signature and remove the inline validate call)
        $updatedContent = str_replace(
            $fullMatch,
            '$request->validated() /* validation moved to ' . $className . ' — update method signature to ' . $className . ' $request */',
            $content
        );

        return [
            $updatedContent,
            ['path' => $requestPath, 'content' => $requestContent],
            $className,
        ];
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

            $cat = $f['category'] ?? 'unknown';
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;

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
