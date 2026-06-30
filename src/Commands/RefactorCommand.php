<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Agents\RefactorAgent;
use CodeGuardian\Laravel\Analyzers\StaticOrchestrator;
use CodeGuardian\Laravel\Console\ConsoleReporter;
use CodeGuardian\Laravel\Console\JustificationCard;
use CodeGuardian\Laravel\Support\AiClient;
use CodeGuardian\Laravel\Support\CodeScanner;
use CodeGuardian\Laravel\Support\ModuleDetector;
use CodeGuardian\Laravel\Support\ReportFormatter;
use CodeGuardian\Laravel\Support\RouteExtractor;
use CodeGuardian\Laravel\Support\TestRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Interactive refactoring workflow:
 *
 *  1. Analyze → show report
 *  2. Ask confirmation to proceed
 *  3. Generate tests FIRST (before any code change)
 *  4. Run generated tests (baseline)
 *  5. Refactor one-by-one (or by module/API)
 *  6. After each refactoring, run tests and show pass/fail
 *  7. If tests fail: ask rollback or continue
 *  8. Generate final before/after report
 */
class RefactorCommand extends Command
{
    // ─── Hard write-gate ─────────────────────────────────────────────────────
    // These files/patterns are NEVER written regardless of what analysis finds.
    // Modifying global routing infrastructure, providers, or kernel files is
    // out of scope for a scoped API/module refactoring operation and can break
    // the entire application if changed incorrectly.

    /** Exact relative paths that are always forbidden from being written. */
    private const FORBIDDEN_EXACT = [
        'routes/web.php',
        'routes/api.php',
        'routes/console.php',
        'app/Providers/RouteServiceProvider.php',
        'app/Providers/AppServiceProvider.php',
        'app/Http/Kernel.php',
        'app/Console/Kernel.php',
        'bootstrap/app.php',
    ];

    /** Path prefix patterns — any file whose relative path contains one of these is forbidden. */
    private const FORBIDDEN_PREFIXES = [
        'config/',
        'app/Providers/',
        'app/Http/Middleware/',
        'bootstrap/',
        'database/migrations/',
        'database/seeders/',
    ];

    /**
     * Check if a relative file path is forbidden from being written.
     * Applied before EVERY static fix write and EVERY AI write.
     */
    private function isForbiddenWrite(string $relPath): bool
    {
        $norm = ltrim(str_replace('\\', '/', $relPath), '/');

        foreach (self::FORBIDDEN_EXACT as $exact) {
            if ($norm === $exact) {
                return true;
            }
        }

        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($norm, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected $signature = 'codeguardian:refactor
                            {--path=              : Project root directory (default: base_path())}
                            {--module=            : Refactor a specific module only (e.g. User, Order)}
                            {--api=               : Refactor APIs matching this filter (e.g. GET:/api/users)}
                            {--file=              : Refactor a single file + its dependency chain (e.g. app/Services/AuthService.php)}
                            {--files=             : Refactor several specific files (comma-separated paths)}
                            {--type=              : Project type: laravel or flutter}
                            {--mode=              : Execution mode: interactive (default) or auto}
                            {--safe                 : Foolproof mode — auto-rollback any file whose refactor introduces a NEW test failure (no prompts)}
                            {--no-backup            : Skip creating backups before modifying files}
                            {--skip-tests           : Skip test execution (not recommended)}
                            {--plain                : Disable the live staged pipeline UI (plain headers)}
                            {--with-existing-tests  : Also run the project existing tests (tests/Feature, tests/Unit) to detect breaking changes}';

    protected $description = 'Analyze → write tests → refactor → verify tests → report (full interactive workflow)';

    private string $projectRoot;
    private string $projectType;
    private bool   $interactiveMode;
    private bool   $backupEnabled;
    private bool   $testsEnabled;
    private bool   $existingTestsEnabled;
    /** Foolproof mode: auto-rollback any file that introduces a new test failure */
    private bool   $safeMode;
    /** Whether an AI provider is configured and mode allows AI refactoring */
    private bool   $aiEnabled;
    /** 'static' | 'ai' | 'hybrid' */
    private string $aiMode;

    /** @var array<string,string> Rollback map: [ relativeFilePath => originalContent ] */
    private array $backups = [];

    /**
     * @var string[] Test names that were ALREADY failing at baseline (before any refactoring).
     * After each refactor step we only alert on failures NOT in this set, so pre-existing
     * broken tests never block the workflow.
     */
    private array $baselineFailingTests = [];

    /** @var StaticOrchestrator  Single instance shared across all workflow steps */
    private StaticOrchestrator $orchestrator;

    /** Premium staged pipeline reporter (null when --plain). */
    private ?ConsoleReporter $reporter = null;

    /** The 7-stage refactoring pipeline, in execution order. */
    private const PIPELINE_STAGES = [
        ['key' => 'discovery', 'label' => 'Project Discovery'],
        ['key' => 'analysis',  'label' => 'Static Analysis'],
        ['key' => 'tests',     'label' => 'Test Generation'],
        ['key' => 'baseline',  'label' => 'Baseline Tests'],
        ['key' => 'refactor',  'label' => 'Refactoring'],
        ['key' => 'verify',    'label' => 'Final Verification'],
        ['key' => 'report',    'label' => 'Report Generation'],
    ];

    /**
     * Transition the pipeline to a new stage. Finishes the previously-running
     * stage, starts the next one. Falls back to a plain banner when --plain.
     */
    private function phase(string $key, string $title): void
    {
        if ($this->reporter === null) {
            $this->section($title);
            return;
        }

        $current = $this->reporter->pipeline()->currentStage();
        if ($current !== null) {
            $this->reporter->finish($current['key']);
        }
        $this->reporter->start($key);
    }

    public function handle(
        CodeScanner     $scanner,
        ReportFormatter $formatter,
    ): int {
        $this->projectRoot          = $this->option('path') ?: base_path();
        $this->projectType          = $this->option('type') ?: $this->detectType();
        $this->interactiveMode      = ($this->option('mode') ?? 'interactive') === 'interactive';
        $this->backupEnabled        = ! $this->option('no-backup');
        $this->testsEnabled         = ! $this->option('skip-tests');
        $this->existingTestsEnabled = (bool) $this->option('with-existing-tests');
        // Foolproof safe mode: explicit --safe flag, or config default, or any
        // non-interactive run (e.g. the web dashboard) where we can't prompt.
        $this->safeMode             = (bool) $this->option('safe')
                                    || (bool) config('codeguardian.refactor.safe_mode', false)
                                    || ($this->option('mode') === 'auto');
        $this->backups              = [];
        $this->orchestrator         = new StaticOrchestrator();

        // Detect AI mode
        $this->aiMode    = config('codeguardian.mode', 'static');
        $provider        = config('codeguardian.provider', 'openai');
        $keyMap          = ['claude' => 'claude.key', 'openai' => 'openai.key', 'gemini' => 'gemini.key'];
        $keyPath         = $keyMap[$provider] ?? 'openai.key';
        $this->aiEnabled = in_array($this->aiMode, ['ai', 'hybrid'], true)
                        && ! empty(config("codeguardian.{$keyPath}"));

        if (! is_dir($this->projectRoot)) {
            $this->error("Path does not exist: {$this->projectRoot}");
            return self::FAILURE;
        }

        $this->printBanner();

        // Premium staged pipeline (non-decorated: a clean scrolling log that
        // coexists with interactive prompts & diffs). Disabled with --plain.
        if (! $this->option('plain')) {
            $this->reporter = new ConsoleReporter(
                $this->output,
                self::PIPELINE_STAGES,
                'CodeGuardian · Refactor',
                false
            );
        }

        $this->phase('discovery', 'STEP 0 — DISCOVERY');

        // ── Step 1: Determine scope ──────────────────────────────────────────
        $scope    = $this->determineScope();
        $context  = $this->buildContext($scanner, $scope);

        $this->info("  Scope   : {$scope['label']}");

        // Show resolution method so we can see if Router or regex fallback was used
        if (isset($context['resolution_method'])) {
            $method = match ($context['resolution_method']) {
                'router+reflection' => '✔ Laravel Router + PHP Reflection (exact)',
                'file+reflection'   => '✔ Target file + PHP Reflection dependency chain',
                default             => '⚠ Regex fallback (Laravel Router unavailable)',
            };
            $this->info("  Resolver: {$method}");
        }

        // Show module boundary — enforced by DependencyTracer and write-gate
        if (! empty($context['module_root'])) {
            $this->info("  Module  : {$context['module_root']}  (only files within this module are in scope)");
        }

        // For --file / --files only the named target(s) are rewritten; the rest
        // is read-only context. Make that explicit so the file count is not
        // mistaken for "files that will change".
        $scopeTargets = $this->scopeTargetFiles($context);
        if ($scopeTargets !== null) {
            $contextCount = max(0, (int) $context['summary']['total_files'] - count($scopeTargets));
            $this->info("  Files   : " . count($scopeTargets) . " to refactor  (+{$contextCount} read-only context)");
        } else {
            $this->info("  Files   : {$context['summary']['total_files']}");
        }

        // Show which files are in scope + WHY each was included
        $reasons = $context['file_reasons'] ?? [];
        if (! empty($context['summary']['file_list'])) {
            foreach ($context['summary']['file_list'] as $f) {
                $reason = isset($reasons[$f]) ? "  ← {$reasons[$f]}" : '';
                $this->line("            → {$f}{$reason}");
            }
        }

        if ($this->aiEnabled) {
            $provider = config('codeguardian.provider', 'claude');
            $model    = config("codeguardian.{$provider}.model", '');
            $this->info("  Engine  : ⚡ Static + 🤖 AI deep-refactoring ({$provider} / {$model})");
        } else {
            $this->info("  Engine  : ⚡ Static only  (set CODEGUARDIAN_MODE=hybrid + API key for AI)");
        }

        if ($this->safeMode) {
            $this->info("  Safety  : 🛡  Foolproof — files that break a test are auto-rolled-back");
        }
        $this->newLine();

        // ── Step 2: Analyze ──────────────────────────────────────────────────
        $this->phase('analysis', 'STEP 1/5 — ANALYZING CODE');
        $analysisResults = $this->runAnalysis($context);

        if (empty($analysisResults['agent_results'])) {
            $this->error('Analysis produced no results. Aborting.');
            return self::FAILURE;
        }

        $this->printAnalysisSummary($analysisResults);

        $totalIssues = $analysisResults['summary']['total_issues'] ?? 0;

        // When AI is enabled AND the user scoped to a specific API/file, the AI
        // deep-refactoring pass is the whole point — the built-in static engine
        // is intentionally basic. So we must NOT stop at "0 static issues"; let
        // Claude perform its expert review. Only bail early for unscoped/no-AI runs.
        $isScopedTarget = (bool) ($this->option('api') || $this->option('file') || $this->option('files'));
        $aiWillReview   = $this->aiEnabled && $isScopedTarget;

        if ($totalIssues === 0 && ! $aiWillReview) {
            $this->info('  ✅  No static issues found.');
            if ($this->aiEnabled) {
                $this->line('  Tip: target a specific API or file (--api= / --file=) to run the AI deep review.');
            }
            return self::SUCCESS;
        }

        if ($totalIssues === 0 && $aiWillReview) {
            $this->info('  ✅  No static issues — handing off to AI for deep expert review...');
        }

        // ── Step 3: Ask to proceed ───────────────────────────────────────────
        if ($this->interactiveMode) {
            $promptCount = $totalIssues > 0
                ? "{$totalIssues} issue(s)"
                : 'AI deep review';
            if (! $this->confirm("  Proceed with refactoring ({$promptCount})?", true)) {
                $this->info('  Refactoring cancelled.');
                return self::SUCCESS;
            }
        }

        // ── Step 4: Generate tests BEFORE any code change ───────────────────
        $this->phase('tests', 'STEP 2/5 — WRITING TESTS (before refactoring)');
        $generatedTestFiles = $this->generateAndWriteTests($analysisResults, $context);

        // ── Step 5: Run baseline tests ───────────────────────────────────────
        $baselineResult = null;
        if ($this->testsEnabled) {
            $this->phase('baseline', 'STEP 3/5 — BASELINE TESTS (before any change)');

            if ($this->existingTestsEnabled) {
                $this->line('  Running CodeGuardian stubs + project existing tests...');
                $this->line('  (Remove --with-existing-tests to run stubs only)');
            } else {
                $this->line('  Running CodeGuardian test stubs only.');
                $this->line('  Tip: add --with-existing-tests to also run tests/Feature, tests/Unit (requires local DB/env).');
            }

            $baselineResult = $this->runTests(cgOnly: false);
            $this->printTestResult('Baseline', $baselineResult);

            // Record every test that is ALREADY failing before we touch anything.
            // Per-file and final checks will only alert on NEW failures that
            // weren't in this set — pre-existing broken tests never block us.
            $this->baselineFailingTests = $this->extractFailingTestNames($baselineResult);

            if (! empty($this->baselineFailingTests)) {
                $count = count($this->baselineFailingTests);
                $this->warn("  ⚠  {$count} test(s) were already failing before refactoring — recorded as baseline.");
                $this->warn('  These will be ignored during the refactoring checks.');
                $this->warn('  Remove --with-existing-tests to skip project tests entirely.');
            }
        }

        // ── Step 6: Refactor files one-by-one ───────────────────────────────
        $this->phase('refactor', 'STEP 4/5 — REFACTORING');
        $refactorResults = $this->runRefactoring($analysisResults, $context);

        // ── Step 7: Verify tests pass after refactoring ──────────────────────
        $finalTestResult = null;
        if ($this->testsEnabled) {
            $this->phase('verify', 'STEP 5/5 — FINAL TEST VERIFICATION');
            $label = $this->existingTestsEnabled
                ? 'CodeGuardian stubs + project existing tests'
                : 'CodeGuardian stubs';
            $this->line("  Running {$label}...");
            $finalTestResult = $this->runTests(cgOnly: false);
            $this->printTestResult('Post-Refactor', $finalTestResult);

            // Surface only new failures in the final summary
            $finalNewFailures = $this->filterNewFailures($finalTestResult);
            if (! empty($finalNewFailures)) {
                $this->newLine();
                $this->error('  🚨 NEW test failures introduced by refactoring:');
                foreach ($finalNewFailures as $f) {
                    $this->warn("     • {$f['test']}");
                }
                // Only prompt rollback when there are actual NEW failures from our changes
                $this->handleTestFailure();
            } elseif (! ($finalTestResult['skipped'] ?? false)) {
                $pre = count(array_filter(
                    $finalTestResult['failures'] ?? [],
                    fn($f) => in_array($f['test'] ?? '', $this->baselineFailingTests, true)
                ));
                if ($pre > 0) {
                    $this->line("  ✅  No new failures. ({$pre} pre-existing failure(s) unchanged.)");
                }
            }
        }

        // ── Step 8: Generate final report ───────────────────────────────────
        $this->phase('report', 'GENERATING FINAL REPORT');
        $finalReport = $this->buildFinalReport($analysisResults, $refactorResults, $baselineResult, $finalTestResult);

        $outputDir = storage_path(config('codeguardian.output.report_dir', 'codeguardian/reports'));
        $paths     = $formatter->save($finalReport, $outputDir, 'both');

        $this->newLine();
        $this->info('  📄 Reports saved:');
        foreach ($paths as $p) {
            $this->line("     → {$p}");
        }

        // Close out the pipeline + show the per-stage time breakdown.
        if ($this->reporter !== null) {
            $current = $this->reporter->pipeline()->currentStage();
            if ($current !== null) {
                $this->reporter->finish($current['key']);
            }
            $this->newLine();
            $this->reporter->executionStats();
        }

        $this->printFinalSummary($refactorResults, $finalTestResult);

        return self::SUCCESS;
    }

    // ─── STEP IMPLEMENTATIONS ────────────────────────────────────────────────

    private function determineScope(): array
    {
        $moduleOpt = $this->option('module');
        $apiOpt    = $this->option('api');
        $fileOpt   = $this->option('file');
        $filesOpt  = $this->option('files');

        if ($filesOpt) {
            $list = array_values(array_filter(array_map('trim', explode(',', (string) $filesOpt)), fn($p) => $p !== ''));
            if (count($list) === 1) {
                return ['type' => 'file', 'value' => $list[0], 'label' => "File: {$list[0]}"];
            }
            return ['type' => 'files', 'value' => $list, 'label' => 'Files: ' . count($list) . ' selected'];
        }

        if ($fileOpt) {
            return ['type' => 'file', 'value' => $fileOpt, 'label' => "File: {$fileOpt}"];
        }

        if ($moduleOpt) {
            return ['type' => 'module', 'value' => $moduleOpt, 'label' => "Module: {$moduleOpt}"];
        }

        if ($apiOpt) {
            return ['type' => 'api', 'value' => $apiOpt, 'label' => "API filter: {$apiOpt}"];
        }

        // Interactive: ask the user
        if ($this->interactiveMode) {
            $detector = new ModuleDetector($this->projectRoot);

            $choices = ['Full project'];

            if ($detector->isModular()) {
                $modules = $detector->listModules();
                $this->info("  Detected module structure: {$detector->detectStructureType()}");
                $this->info("  Modules: " . implode(', ', $modules));
                $choices = array_merge($choices, array_map(fn($m) => "Module: {$m}", $modules));
            }

            $extractor = new RouteExtractor($this->projectRoot);
            $routes    = $extractor->extractAll();
            if (! empty($routes)) {
                $choices[] = 'Specific API / route filter';
            }

            $choices[] = 'Specific file';

            $choice = $this->choice('What scope do you want to refactor?', $choices, 0);

            if (str_starts_with($choice, 'Module:')) {
                $module = trim(str_replace('Module:', '', $choice));
                return ['type' => 'module', 'value' => $module, 'label' => "Module: {$module}"];
            }

            if ($choice === 'Specific API / route filter') {
                $this->info('  Examples: "GET:/api/users", "POST:/api", "UserController", "users"');
                $filter = $this->ask('Enter route filter');
                return ['type' => 'api', 'value' => $filter, 'label' => "API filter: {$filter}"];
            }

            if ($choice === 'Specific file') {
                $this->info('  Example: app/Services/AuthService.php or Modules/User/Http/Controllers/UserController.php');
                $file = $this->ask('Enter file path (relative to project root)');
                return ['type' => 'file', 'value' => $file, 'label' => "File: {$file}"];
            }
        }

        return ['type' => 'full', 'value' => null, 'label' => 'Full project'];
    }

    private function buildContext(CodeScanner $scanner, array $scope): array
    {
        return match ($scope['type']) {
            'module' => $scanner->buildContextForModule($this->projectRoot, $scope['value']),
            'api'    => $scanner->buildContextForApi($this->projectRoot, $scope['value']),
            'file'   => $scanner->buildContextForFile($this->projectRoot, $scope['value']),
            'files'  => $scanner->buildContextForFiles($this->projectRoot, $scope['value']),
            default  => $scanner->buildContext($this->projectRoot, $this->projectType),
        };
    }

    private function runAnalysis(array $context): array
    {
        $this->line('');
        $files = $context['files'] ?? [];
        $raw   = $this->orchestrator->analyze($files, [], $this->projectRoot);

        // Normalise to legacy format used by the rest of this command
        $agentResults = [];
        foreach ($raw['agents'] as $agent) {
            $agentResults[$agent['agent']] = $agent;
        }

        $allIssues = $raw['all_findings'];
        $summary   = $raw['summary'];

        foreach ($raw['agents'] as $agent) {
            $this->line("  ✔  {$agent['agent']} ({$agent['summary']['total_issues']} issues)");
        }

        return [
            'overall_score' => $raw['overall_score'],
            'grade'         => $raw['grade'],
            'agent_results' => $agentResults,
            'all_findings'  => $allIssues,
            'summary'       => [
                'total_issues' => $summary['total_issues'],
                'critical'     => $summary['by_severity']['critical'],
                'high'         => $summary['by_severity']['high'],
                'medium'       => $summary['by_severity']['medium'],
                'low'          => $summary['by_severity']['low'],
            ],
            'engine'        => 'static',
        ];
    }

    private function generateAndWriteTests(array $analysisResults, array $context): array
    {
        $testsDir = base_path(config('codeguardian.output.tests_dir', 'tests/CodeGuardian'));
        File::ensureDirectoryExists($testsDir);

        // Only generate tests for files that HAVE findings — not every scanned file
        $filesToTest   = array_keys($this->groupFindingsByFile($analysisResults['agent_results']));

        // For --file / --files, only the named target file(s) are refactored, so
        // only generate tests for those — not the entire traced dependency chain.
        $targets = $this->scopeTargetFiles($context);
        if ($targets !== null) {
            $filesToTest = array_values(array_intersect($filesToTest, $targets));
        }

        $relevantFiles = array_filter(
            $context['files'] ?? [],
            fn($path) => in_array($path, $filesToTest, true),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($relevantFiles)) {
            $this->warn('  No files with findings to generate tests for.');
            return [];
        }

        $this->line('  Generating test stubs for ' . count($relevantFiles) . ' file(s) with issues...');
        $generatedTests = $this->orchestrator->generateTests($relevantFiles);

        $writtenFiles = [];
        foreach ($generatedTests as $test) {
            $filePath = $testsDir . '/' . $test->className . '.php';
            $testCode = $test->content;

            if (empty($testCode)) {
                continue;
            }

            File::put($filePath, $testCode);
            $writtenFiles[] = $filePath;
            $this->info("  ✔  Test written: {$filePath}");
        }

        if (empty($writtenFiles)) {
            $this->warn('  No tests were generated. Continuing without tests.');
        } else {
            $this->info('  ' . count($writtenFiles) . ' test file(s) written to tests/CodeGuardian/');
        }

        return $writtenFiles;
    }

    /**
     * Run CodeGuardian-generated stubs AND (optionally) the project's existing
     * tests to detect breaking changes caused by refactoring.
     *
     * @param  bool  $cgOnly  When true, only run tests/CodeGuardian/ (fast per-file check).
     *                        When false, also run the project's existing tests/Feature|Unit.
     */
    private function runTests(bool $cgOnly = false): array
    {
        if (! $this->testsEnabled) {
            return ['passed' => true, 'total' => 0, 'skipped' => true];
        }

        $runner = new TestRunner($this->projectRoot);

        if (! $runner->isAvailable($this->projectType)) {
            $this->warn('  PHPUnit/Pest not found. Skipping test run.');
            return ['passed' => true, 'total' => 0, 'skipped' => true];
        }

        if ($cgOnly || ! $this->existingTestsEnabled) {
            // Quick check: only generated stubs
            return $runner->runCodeGuardianTests($this->projectType);
        }

        // Full check: generated stubs + project's existing tests
        return $runner->runAll($this->projectType);
    }

    /**
     * The set of files that should actually be REWRITTEN for the current scope.
     *
     * For `--file` / `--files`, the user named the file(s) they want changed;
     * the traced dependency chain is read-only CONTEXT for the AI, not a set of
     * extra files to refactor. (Refactoring the whole transitive chain of a fat
     * console command pulled in dozens of unrelated repositories/models.)
     *
     * Returns null for api / module / project scope, where refactoring the whole
     * resolved scope (controller → service → repository) is the desired
     * behaviour.
     *
     * @param  array<string,mixed> $context
     * @return array<int,string>|null
     */
    private function scopeTargetFiles(array $context): ?array
    {
        if (! in_array($context['scope'] ?? null, ['file', 'files'], true)) {
            return null;
        }

        // Preferred: the explicit, normalised target list recorded by the scanner.
        if (! empty($context['refactor_targets']) && is_array($context['refactor_targets'])) {
            $targets = array_values(array_filter(array_map(
                fn($p) => ltrim((string) $p, '/'),
                $context['refactor_targets']
            ), fn($p) => $p !== ''));

            if ($targets !== []) {
                return array_values(array_unique($targets));
            }
        }

        // Fallback (older context shape): derive from file_reasons.
        $targets = [];
        foreach (($context['file_reasons'] ?? []) as $path => $reason) {
            if (str_starts_with((string) $reason, 'target file')) {
                $targets[] = $path;
            }
        }

        return $targets !== [] ? $targets : null;
    }

    private function runRefactoring(array $analysisResults, array $context): array
    {
        $findingsByFile = $this->groupFindingsByFile($analysisResults['agent_results']);

        // For --file / --files, refactor ONLY the named target file(s). The
        // traced dependency chain stays as read-only AI context (it is still in
        // $context['files']) but is never rewritten — this prevents a single
        // file's transitive dependencies from dragging dozens of unrelated
        // files into the refactor.
        $scopeTargets = $this->scopeTargetFiles($context);
        if ($scopeTargets !== null) {
            $findingsByFile = array_intersect_key($findingsByFile, array_flip($scopeTargets));
        }

        // When --api= or --file= is used and AI is enabled, we MUST NOT bail early even if
        // static analysis found nothing. The deep-chain pass will run AI on the traced
        // dependency layer regardless. Without a scoped target, an empty findings list
        // means there is nothing to do.
        $isScopedTarget = $this->option('api') || $this->option('file') || $this->option('files');
        $hasApiScope    = $this->aiEnabled && $isScopedTarget && ! empty($context['files']);

        if (empty($findingsByFile) && ! $hasApiScope) {
            $this->warn('  No file-specific findings to refactor.');
            return [];
        }

        if (empty($findingsByFile)) {
            $this->line('  ✔  Static analysis: no issues found. Proceeding to AI deep-chain review...');
        }

        $refactorResults = [];
        $fileCount       = 0;
        $totalFiles      = count($findingsByFile);
        $projectRealPath = realpath($this->projectRoot) ?: $this->projectRoot;

        // Ensure disk-backup directory exists (crash-safe rollback)
        $backupRoot = storage_path('codeguardian/backups/' . date('Y-m-d_H-i-s'));
        if ($this->backupEnabled) {
            File::ensureDirectoryExists($backupRoot);
            $this->line("  💾 Backups stored at: {$backupRoot}");
        }

        // Hoist TestRunner — one instance for all per-file test runs.
        // Run after every file in interactive mode; skip in auto mode for speed.
        $runner = ($this->testsEnabled && $this->interactiveMode)
            ? new TestRunner($this->projectRoot)
            : null;

        foreach ($findingsByFile as $filePath => $issues) {
            $fileCount++;
            $this->newLine();
            $this->info("  [{$fileCount}/{$totalFiles}] {$filePath}");

            // Justify WHY each change is recommended (why / benefit / risk /
            // effort / breaking-change / taxonomy) — never refactor blindly.
            $this->renderJustifications($issues);

            // ── Resolve + validate file path ────────────────────────────────
            $candidatePath = $projectRealPath . DIRECTORY_SEPARATOR . ltrim($filePath, '/\\');
            $fullPath      = realpath($candidatePath) ?: $candidatePath;

            if (! str_starts_with($fullPath, $projectRealPath)) {
                $this->error("  Unsafe path rejected (outside project root): {$filePath}");
                continue;
            }

            if (! file_exists($fullPath)) {
                $this->warn("  File not found: {$fullPath} — skipping.");
                continue;
            }

            // ── Hard write-gate ──────────────────────────────────────────────
            // Global routes, service providers, kernel files, config/, and
            // middleware are NEVER written — they are infrastructure, not
            // request-handler code. Modifying them during a scoped API
            // refactoring would silently break the entire application.
            if ($this->isForbiddenWrite($filePath)) {
                $this->warn("  ⛔ SKIPPED (protected infrastructure file): {$filePath}");
                $this->line("     Route/Provider/Kernel/config files are never modified.");
                $this->line("     If a real issue exists here, fix it manually.");
                continue;
            }

            $originalContent = File::get($fullPath);

            // ── Disk backup BEFORE any change ───────────────────────────────
            if ($this->backupEnabled) {
                $backupFile = $backupRoot . '/' . str_replace(['/', '\\'], '__', $filePath);
                File::put($backupFile, $originalContent);
                $this->backups[$filePath] = $originalContent; // in-memory copy as well
            }

            // ── Compute refactoring result ───────────────────────────────────
            $this->line('  Analyzing and preparing changes...');
            $result = $this->orchestrator->refactorFile($filePath, $originalContent, $issues);

            $staticApplied = false;

            // ── Show what WILL change (preview diff) before writing ──────────
            if ($result->hasChanges()) {
                $this->newLine();
                $this->info('  CHANGES PREVIEW:');
                foreach ($result->changes as $change) {
                    $icon = str_starts_with($change, '[MANUAL]') ? '  ⚠  ' : '  ✔  ';
                    $this->line($icon . $change);
                }

                // Show unified diff (first 30 lines)
                $diffLines = array_slice(explode("\n", $result->diff()), 0, 30);
                if (! empty($diffLines)) {
                    $this->newLine();
                    $this->line('  DIFF (first 30 lines):');
                    foreach ($diffLines as $diffLine) {
                        if (str_starts_with($diffLine, '+')) {
                            $this->info('  ' . $diffLine);
                        } elseif (str_starts_with($diffLine, '-')) {
                            $this->warn('  ' . $diffLine);
                        }
                    }
                }

                // ── Final syntax safety gate (combined static result) ────────
                if (! $this->orchestrator->isValidPhp($result->refactored)) {
                    $this->error("  ⚠️  SAFETY GATE: refactored file has PHP syntax errors — static write ABORTED.");
                    $this->error("  Original file is unchanged. Falling back to AI pass only.");
                } else {
                    // ── Write static-refactored content to disk ──────────────
                    File::put($fullPath, $result->refactored);

                    // ── Write any generated files (FormRequests, Services…) ──
                    foreach ($result->generatedFiles as $genPath => $genContent) {
                        $absGenPath = str_starts_with($genPath, '/')
                            ? $genPath
                            : $this->projectRoot . '/' . $genPath;

                        if (! File::exists($absGenPath)) {
                            File::ensureDirectoryExists(dirname($absGenPath));
                            File::put($absGenPath, $genContent);
                            $this->info("  📄 Generated: {$genPath}");
                        } else {
                            $this->line("  ⚠  Skipped (already exists): {$genPath}");
                        }
                    }

                    $this->info("  ✔  Static fixes saved ({$result->autoFixed} auto-fix(es), {$result->manualTodos} manual TODO(s))");
                    $staticApplied = true;
                }
            } else {
                // No auto-fixable static patterns. This is NOT a reason to skip
                // the file — most real structural work (method extraction, query
                // rewrites, caching, breaking up fat classes) is done by the AI
                // pass below, not by regex auto-fixes. Previously this branch
                // `continue`d and the file (e.g. BaseLogin, token repositories)
                // was never sent to the AI at all.
                $this->line('  No auto-fixable static patterns — handing to AI deep-refactor.');
                foreach ($result->changes as $change) {
                    $this->line('  ⚠  ' . $change);
                }
            }

            // User already confirmed "Proceed with refactoring N issues?" at Step 3.
            // Per-file confirmation is removed — apply all changes automatically.
            // Backups are always written before any file is touched, so every change
            // can be rolled back via the rollback prompt if tests fail.

            $aiChanges = [];

            // ── AI deep-refactoring pass (mode=ai or mode=hybrid) ────────────
            // Runs for EVERY in-scope file with findings, regardless of whether
            // static auto-fixes were available. The AI re-reads the file from
            // disk inside applyAiRefactoring, so it sees any static fixes already
            // applied above.
            if ($this->aiEnabled) {
                // Pass the entire in-scope file set as read-only context so Claude
                // sees the full call chain (controller → service → repository) and
                // can make informed decisions about what goes where.
                $relatedContextFiles = $context['files'] ?? [];
                $aiChanges = $this->applyAiRefactoring(
                    $filePath,
                    $fullPath,
                    $issues,
                    $relatedContextFiles,
                    $context['module_root'] ?? null
                );
            } elseif ($this->aiMode !== 'static') {
                $this->line('  ⚡ AI provider not configured — static-only mode.');
            }

            // ── Determine final status ───────────────────────────────────────
            if (! $staticApplied && empty($aiChanges)) {
                // Nothing was actually changed — only manual TODOs remain.
                $refactorResults[$filePath] = [
                    'status'       => 'manual_required',
                    'issues'       => count($issues),
                    'auto_fixed'   => 0,
                    'manual_todos' => $result->manualTodos,
                    'changes'      => $result->changes,
                ];
                continue;
            }

            $refactorResults[$filePath] = [
                'status'       => 'refactored',
                'issues'       => count($issues),
                'auto_fixed'   => $result->autoFixed,
                'manual_todos' => $result->manualTodos,
                'changes'      => array_merge($result->changes, $aiChanges),
            ];

            // ── Per-file test verification ────────────────────────────────────
            if ($runner !== null) {
                $this->line('  Running tests to verify...');
                $this->line('    → CodeGuardian stubs (tests/CodeGuardian/)');

                // Run CodeGuardian stubs first (fast)
                $cgResult = $runner->runCodeGuardianTests($this->projectType);
                $this->printTestResult('  Stubs', $cgResult);

                // Run existing project tests too (breaking-change detector)
                $existingResult = null;
                if ($this->existingTestsEnabled) {
                    $this->line('    → Project existing tests (tests/Feature/, tests/Unit/, ...)');
                    $existingResult = $runner->runExistingProjectTests($this->projectType);
                    $this->printTestResult('  Existing', $existingResult);
                }

                // Combine and decide — but first strip pre-existing failures so
                // we only alert on NEW breakages introduced by this refactoring.
                $testResult = $existingResult !== null
                    ? $runner->mergeResults($cgResult, $existingResult)
                    : $cgResult;

                $newFailures = $this->filterNewFailures($testResult);

                if (! empty($newFailures)) {
                    $newCount = count($newFailures);

                    // Tell the user WHICH suite the new failures came from
                    $fromExisting = $existingResult !== null
                        && ! empty($this->filterNewFailures($existingResult));

                    if ($fromExisting) {
                        $this->error("  ⚠️  BREAKING CHANGE detected after refactoring {$filePath}!");
                        $this->error("  {$newCount} existing project test(s) that were passing before now fail.");
                    } else {
                        $this->error("  ⚠️  {$newCount} test(s) FAILED after refactoring {$filePath}!");
                    }

                    foreach ($newFailures as $f) {
                        $this->warn("     FAIL: {$f['test']}");
                        if (! empty($f['message'])) {
                            $this->line("           {$f['message']}");
                        }
                    }

                    // ── Foolproof safe mode ──────────────────────────────────
                    // The whole point of "test → refactor → verify" is that a
                    // refactor that breaks a test must NOT ship. In safe mode
                    // (the dashboard, --safe, or any non-interactive run) we
                    // automatically restore the original file so the refactored
                    // code is always green.
                    if ($this->safeMode || ! $this->interactiveMode) {
                        if (config('codeguardian.refactor.auto_rollback_on_fail', true)) {
                            $this->rollbackFile($filePath, $fullPath);
                            $refactorResults[$filePath]['status'] = 'rolled_back';
                            $this->warn("  ↩  Auto-rolled back {$filePath} (refactor introduced {$newCount} new failure(s); original restored).");
                        } else {
                            $refactorResults[$filePath]['status'] = 'failed_verification';
                            $this->warn("  ⚠  Kept changes despite {$newCount} new failure(s) (auto-rollback disabled).");
                        }
                    } else {
                        $choice = $this->choice(
                            'What do you want to do?',
                            ['Rollback this file', 'Continue anyway', 'Stop refactoring'],
                            0
                        );

                        if ($choice === 'Rollback this file') {
                            $this->rollbackFile($filePath, $fullPath);
                            $refactorResults[$filePath]['status'] = 'rolled_back';
                        } elseif ($choice === 'Stop refactoring') {
                            $this->stopRefactoring();
                            return $refactorResults;
                        }
                    }
                } elseif (! ($testResult['skipped'] ?? false)) {
                    // All failures are pre-existing — not our fault
                    $preExisting = count($testResult['failures'] ?? []);
                    if ($preExisting > 0) {
                        $this->line("  ✅  No NEW failures (pre-existing: {$preExisting} ignored).");
                    }
                }
            }
        }

        // ── API deep-chain pass: AI refactor files that had NO static findings ─
        // When --api= is specified, the scope includes service/repository files
        // that were pulled in via findRelatedServices. Static analysis may find
        // nothing in them (e.g. the service is syntactically clean but has
        // structural issues only AI detects). Run an AI-only pass on every
        // in-scope file that was NOT already processed in the main loop above.
        if ($hasApiScope) {
            $allContextFiles  = $context['files'];
            $alreadyProcessed = array_keys($findingsByFile);

            // For --file / --files, the deep-chain pass must only cover the named
            // target file(s) (so a target with no static findings still gets an
            // AI pass) — NOT the whole traced dependency chain.
            $scopeTargets = $this->scopeTargetFiles($context);
            if ($scopeTargets !== null) {
                $allContextFiles = array_intersect_key($allContextFiles, array_flip($scopeTargets));
            }

            $unprocessed = array_filter(
                $allContextFiles,
                fn($relPath) => ! in_array($relPath, $alreadyProcessed, true),
                ARRAY_FILTER_USE_KEY
            );

            // For --file / --files the "deep-chain" is really just the named
            // target(s); for --api it is the service/repository layer. Label
            // accordingly so the output is never misleading.
            $isFileScope = $scopeTargets !== null;

            if (! empty($unprocessed)) {
                $this->newLine();
                $this->info($isFileScope
                    ? '  🔍 Running AI expert review on the target file...'
                    : '  🔍 API deep-chain: running AI review on service/repository layer...');

                foreach ($unprocessed as $relPath => $_) {
                    $candidatePath = $projectRealPath . DIRECTORY_SEPARATOR . ltrim($relPath, '/\\');
                    $fullPath      = realpath($candidatePath) ?: $candidatePath;

                    if (! file_exists($fullPath)) {
                        continue;
                    }

                    if (! str_starts_with($fullPath, $projectRealPath)) {
                        continue;
                    }

                    if ($this->isForbiddenWrite($relPath)) {
                        continue; // silently skip infrastructure in deep-chain pass
                    }

                    $this->newLine();
                    $this->info('  [' . ($isFileScope ? 'target' : 'service/repo') . "] {$relPath}");
                    $this->line('  No static findings — running AI-only expert review...');

                    if ($this->backupEnabled) {
                        $backupFile             = $backupRoot . '/' . str_replace(['/', '\\'], '__', $relPath);
                        $this->backups[$relPath] = File::get($fullPath);
                        File::put($backupFile, $this->backups[$relPath]);
                    }

                    // Pass zero static issues — AI will do its own independent review
                    $aiChanges = $this->applyAiRefactoring(
                        $relPath,
                        $fullPath,
                        [],
                        $allContextFiles,
                        $context['module_root'] ?? null
                    );

                    if (! empty($aiChanges)) {
                        $refactorResults[$relPath] = [
                            'status'       => 'ai_only',
                            'issues'       => 0,
                            'auto_fixed'   => 0,
                            'manual_todos' => 0,
                            'changes'      => $aiChanges,
                        ];
                    }
                }
            }
        }

        return $refactorResults;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function groupFindingsByFile(array $agentResults): array
    {
        $byFile = [];

        foreach ($agentResults as $agentName => $result) {
            if ($agentName === 'qa') {
                continue; // QA generates tests, not file-level findings
            }

            foreach ($result['findings'] ?? [] as $finding) {
                $file = $finding['file'] ?? null;
                if (! $file) {
                    continue;
                }

                // Only include files that exist in the project
                $fullPath = $this->projectRoot . '/' . ltrim($file, '/');
                if (! file_exists($fullPath)) {
                    continue;
                }

                $byFile[$file][] = $finding;
            }
        }

        // Sort by number of issues descending (fix most-impacted files first)
        uasort($byFile, fn($a, $b) => count($b) - count($a));

        return $byFile;
    }

    private function rollbackFile(string $filePath, string $fullPath): void
    {
        if (isset($this->backups[$filePath])) {
            File::put($fullPath, $this->backups[$filePath]);
            $this->info("  ↩  Rolled back: {$filePath}");
        } else {
            $this->warn("  No backup available for: {$filePath}");
        }
    }

    /**
     * Roll back ALL changed files to their backed-up originals.
     * Separated from the return-flow — caller decides what to do after.
     */
    private function stopRefactoring(): void
    {
        $this->warn('  Stopping refactoring. Rolling back all changed files...');
        foreach ($this->backups as $filePath => $originalContent) {
            $fullPath = $this->projectRoot . '/' . ltrim($filePath, '/');
            File::put($fullPath, $originalContent);
            $this->line("  ↩  Rolled back: {$filePath}");
        }
    }

    private function handleTestFailure(): void
    {
        $this->newLine();
        $this->error('  ⚠️  Tests failed after refactoring!');

        if (! $this->interactiveMode) {
            return;
        }

        $choice = $this->choice(
            'Tests are failing. What do you want to do?',
            ['Rollback all changes', 'Keep changes and review manually', 'Ignore and continue'],
            0
        );

        if ($choice === 'Rollback all changes') {
            $this->stopRefactoring();
            $this->info('  All changes rolled back.');
        }
    }

    private function buildFinalReport(
        array  $analysis,
        array  $refactorResults,
        ?array $baselineTest,
        ?array $finalTest
    ): array {
        $filesRefactored = count(array_filter($refactorResults, fn($r) => ($r['status'] ?? '') === 'refactored'));
        $totalChanges    = array_sum(array_map(fn($r) => count($r['changes'] ?? []), $refactorResults));

        return array_merge($analysis, [
            'report_type'        => 'refactoring',
            'refactor_results'   => $refactorResults,
            'files_refactored'   => $filesRefactored,
            'total_changes'      => $totalChanges,
            'baseline_tests'     => $baselineTest,
            'final_tests'        => $finalTest,
            'tests_passed'       => $finalTest['passed'] ?? null,
        ]);
    }

    // ─── Display helpers ─────────────────────────────────────────────────────

    private function printBanner(): void
    {
        $this->newLine();
        $this->info('  ╔══════════════════════════════════════════════════╗');
        $this->info('  ║     CodeGuardian AI — Interactive Refactoring    ║');
        $this->info('  ║   Analyze → Test → Refactor → Verify → Report   ║');
        $this->info('  ╚══════════════════════════════════════════════════╝');
        $this->newLine();
        $this->info("  Project : {$this->projectRoot}");
        $this->info("  Type    : {$this->projectType}");
        $this->info("  Mode    : " . ($this->interactiveMode ? 'Interactive' : 'Auto'));
        $this->info("  Backup  : " . ($this->backupEnabled ? 'Enabled' : 'Disabled'));
        $this->newLine();
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info("  {$title}");
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();
    }

    private function printAnalysisSummary(array $results): void
    {
        $summary = $results['summary'] ?? [];
        $overall = $results['overall_score'] ?? 'N/A';
        $grade   = $results['grade'] ?? '';

        $this->newLine();
        $overallColor = is_int($overall) ? ($overall >= 80 ? 'info' : ($overall >= 60 ? 'warn' : 'error')) : 'info';
        $this->{$overallColor}("  Overall Score : {$overall}/100  (Grade: {$grade})");

        // Extract per-agent scores from agent_results (not a separate 'scores' key)
        foreach ($results['agent_results'] ?? [] as $agentData) {
            foreach (array_keys($agentData) as $k) {
                if (str_ends_with($k, '_score')) {
                    $label = ucwords(str_replace(['_score', '_'], [' ', ' '], $k));
                    $val   = $agentData[$k];
                    $color = $val >= 80 ? 'info' : ($val >= 60 ? 'warn' : 'error');
                    $this->{$color}("  {$label} : {$val}/100");
                    break;
                }
            }
        }
        $this->newLine();

        $critical = $summary['critical'] ?? 0;
        $high     = $summary['high'] ?? 0;
        $total    = $summary['total_issues'] ?? 0;

        $this->line("  Total Issues : {$total}");
        if ($critical > 0) $this->error("  Critical     : {$critical}");
        if ($high > 0)     $this->warn("  High         : {$high}");
        $this->newLine();
    }

    // ─── AI deep-refactoring ─────────────────────────────────────────────────

    /**
     * Send the (already-static-fixed) file to Claude / GPT / Gemini for deep
     * structural refactoring: method splitting, service extraction, query
     * optimization, design patterns — things regex cannot do.
     *
     * Returns the list of AI-generated change descriptions (for the final report).
     *
     * @return list<string>
     */
    /**
     * @param  array $relatedContextFiles  All in-scope files for this operation (keyed by relative path).
     *                                     Passed to Claude as read-only context so it understands the
     *                                     full controller → service → repository call chain.
     */
    private function applyAiRefactoring(
        string $filePath,
        string $fullPath,
        array  $issues,
        array  $relatedContextFiles = [],
        ?string $moduleRoot = null
    ): array {
        $provider = config('codeguardian.provider', 'claude');
        $this->newLine();
        $this->info("  🤖 Running AI deep-refactoring ({$provider})...");
        $this->line('     Structural improvements: method splitting, service extraction,');
        $this->line('     query optimization, design patterns...');

        // Read current file (may already have static fixes applied)
        $currentContent = File::get($fullPath);

        // Filter to issues that still need structural work
        $structuralCategories = [
            'fat_controller', 'fat_model', 'service_layer', 'solid',
            'high_complexity', 'large_class', 'deep_nesting', 'long_method',
            'duplication', 'dependency_injection', 'n_plus_one', 'missing_cache',
            'missing_index', 'sql_injection', 'authorization', 'magic_numbers',
        ];
        $relevantIssues = array_filter($issues, fn($i) =>
            in_array($i['category'] ?? '', $structuralCategories, true)
        );

        if (empty($relevantIssues)) {
            $relevantIssues = $issues; // pass all if no structural ones specifically
        }

        try {
            $agent     = new RefactorAgent();
            $apiFilter = $this->option('api') ?: null;
            $result     = $agent->refactorFile(
                $filePath,
                $currentContent,
                array_values($relevantIssues),
                $apiFilter,
                $relatedContextFiles,
                $moduleRoot
            );

            if (! empty($result['error'])) {
                $this->warn("  ⚠  AI refactoring error: {$result['error']}");
                return [];
            }

            $aiContent = $result['refactored_file'] ?? null;
            $aiChanges = $result['changes'] ?? [];

            if (empty($aiContent) || $aiContent === $currentContent) {
                $this->line('  ℹ  AI: no further structural changes needed.');
                return [];
            }

            // Safety gate — validate the AI-generated PHP
            if (! $this->orchestrator->isValidPhp($aiContent)) {
                $this->warn('  ⚠  AI-generated code has PHP syntax errors — AI pass skipped.');
                return [];
            }

            // Final gate — AI must never write to global infrastructure files
            if ($this->isForbiddenWrite($filePath)) {
                $this->warn("  ⛔ AI write blocked (protected infrastructure file): {$filePath}");
                return [];
            }

            // ─────────────────────────────────────────────────────────────────
            // WRITE FIRST, DISPLAY SECOND.
            // The refactored code is the product; the on-screen summary is
            // cosmetic. Persisting before any rendering guarantees a display
            // bug (e.g. a malformed tests_needed entry) can NEVER discard a
            // valid refactor. This was a real bug: an "Array to string
            // conversion" while printing tests silently threw away every AI
            // rewrite because File::put ran after the print.
            // ─────────────────────────────────────────────────────────────────
            File::put($fullPath, $aiContent);

            // Write any new files Claude generated (Services, FormRequests, etc.)
            foreach (($result['generated_files'] ?? []) as $genPath => $genContent) {
                if (! is_string($genPath) || ! is_string($genContent) || empty($genContent)) {
                    continue;
                }
                $absGenPath = str_starts_with($genPath, '/')
                    ? $genPath
                    : $this->projectRoot . '/' . ltrim($genPath, '/');

                if (! File::exists($absGenPath)) {
                    File::ensureDirectoryExists(dirname($absGenPath));
                    File::put($absGenPath, $genContent);
                    $this->info("  📄 AI generated: {$genPath}");
                } else {
                    $this->line("  ⚠  Already exists (skipped): {$genPath}");
                }
            }

            // ── Display (best-effort; never allowed to abort the saved work) ──
            try {
                $this->newLine();
                $this->info('  🤖 AI REFACTORING CHANGES:');
                foreach ($aiChanges as $change) {
                    $type = is_array($change) ? ($change['type'] ?? 'change') : 'change';
                    $desc = is_array($change)
                        ? ($change['description'] ?? '')
                        : (is_string($change) ? $change : json_encode($change));
                    $this->line("  ✔  [{$type}] {$desc}");
                }

                if (! empty($result['tests_needed'])) {
                    $this->newLine();
                    $this->line('  📋 Recommended tests to write:');
                    foreach ($result['tests_needed'] as $test) {
                        $this->line('     • ' . $this->stringifyTest($test));
                    }
                }

                $diffLines = $this->quickDiff($currentContent, $aiContent, 40);
                if (! empty($diffLines)) {
                    $this->newLine();
                    $this->line('  DIFF (AI changes):');
                    foreach ($diffLines as $line) {
                        if (str_starts_with($line, '+')) {
                            $this->info('  ' . $line);
                        } elseif (str_starts_with($line, '-')) {
                            $this->warn('  ' . $line);
                        }
                    }
                }
            } catch (\Throwable $displayError) {
                // Display problems must not undo the already-saved refactor.
                $this->line('  (summary display skipped: ' . $displayError->getMessage() . ')');
            }

            $this->info("  ✔  AI refactoring saved (" . count($aiChanges) . " structural change(s))");

            return array_map(
                fn($c) => is_array($c)
                    ? '[AI] ' . ($c['type'] ?? '') . ': ' . ($c['description'] ?? '')
                    : '[AI] ' . (is_string($c) ? $c : json_encode($c)),
                $aiChanges
            );

        } catch (\Throwable $e) {
            $this->warn("  ⚠  AI refactoring failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Render a single "tests_needed" entry as a readable line.
     * Handles both the structured object form ({scenario, type, priority,
     * description}) and the legacy plain-string form.
     */
    private function stringifyTest(mixed $test): string
    {
        if (is_string($test)) {
            return $test;
        }

        if (is_array($test)) {
            $scenario = $test['scenario'] ?? $test['name'] ?? null;
            $desc     = $test['description'] ?? null;

            if ($scenario && $desc) {
                return "{$scenario} — {$desc}";
            }
            if ($scenario) {
                return (string) $scenario;
            }
            if ($desc) {
                return (string) $desc;
            }

            return json_encode($test);
        }

        return (string) $test;
    }

    /** Simple line-by-line diff, returns first $maxLines changed lines */
    private function quickDiff(string $before, string $after, int $maxLines): array
    {
        $beforeLines = explode("\n", $before);
        $afterLines  = explode("\n", $after);
        $diff        = [];
        $count       = 0;
        $maxLen      = max(count($beforeLines), count($afterLines));

        for ($i = 0; $i < $maxLen && $count < $maxLines; $i++) {
            $b = $beforeLines[$i] ?? null;
            $a = $afterLines[$i] ?? null;
            if ($b !== $a) {
                if ($b !== null) {
                    $diff[] = '- ' . $b;
                    $count++;
                }
                if ($a !== null) {
                    $diff[] = '+ ' . $a;
                    $count++;
                }
            }
        }

        return $diff;
    }

    // ─── Baseline-aware failure helpers ──────────────────────────────────────

    /**
     * Extract a flat list of test names from a result's failures array.
     *
     * @return string[]
     */
    private function extractFailingTestNames(array $result): array
    {
        return array_values(array_filter(array_map(
            fn(array $f) => $f['test'] ?? null,
            $result['failures'] ?? []
        )));
    }

    /**
     * Return only the failures that are NEW — i.e. not in the baseline set.
     * Pre-existing failures (recorded before any refactoring) are excluded.
     *
     * @return array<array{test:string,message:string}>
     */
    private function filterNewFailures(array $result): array
    {
        if (empty($result['failures'])) {
            return [];
        }

        return array_values(array_filter(
            $result['failures'],
            fn(array $f) => ! in_array($f['test'] ?? '', $this->baselineFailingTests, true)
        ));
    }

    // ─── Test output ─────────────────────────────────────────────────────────

    private function printTestResult(string $label, array $result): void
    {
        if ($result['skipped'] ?? false) {
            $reason = $result['reason'] ?? 'no tests found';
            $this->line("  [{$label}] Skipped ({$reason})");
            return;
        }

        $icon   = ($result['passed'] ?? true) ? '✅' : '❌';
        $total  = $result['total'] ?? 0;
        $passed = $result['passed_count'] ?? 0;
        $failed = $result['failed_count'] ?? 0;
        $ms     = $result['duration_ms'] ?? 0;

        $this->line("  {$icon} {$label}: {$passed}/{$total} passed, {$failed} failed ({$ms}ms)");

        // Show per-source breakdown when both suites were run
        if (isset($result['sources'])) {
            $cg  = $result['sources']['codeguardian'] ?? null;
            $ex  = $result['sources']['existing']     ?? null;

            if ($cg && ! ($cg['skipped'] ?? false)) {
                $cgIcon = ($cg['passed'] ?? true) ? '✅' : '❌';
                $this->line("       {$cgIcon} CodeGuardian stubs : {$cg['passed_count']}/{$cg['total']} ({$cg['duration_ms']}ms)");
            }
            if ($ex && ! ($ex['skipped'] ?? false)) {
                $exIcon = ($ex['passed'] ?? true) ? '✅' : '❌';
                $this->line("       {$exIcon} Existing project   : {$ex['passed_count']}/{$ex['total']} ({$ex['duration_ms']}ms)");
            }
        }

        foreach ($result['failures'] ?? [] as $failure) {
            $this->warn("     FAIL: {$failure['test']}");
            if (! empty($failure['message'])) {
                $this->line("           {$failure['message']}");
            }
        }
    }

    /**
     * Render justification cards for the issues in a file (most severe first),
     * capped so a busy file doesn't flood the terminal.
     *
     * @param array<int,array<string,mixed>> $issues
     */
    private function renderJustifications(array $issues): void
    {
        if (empty($issues)) {
            return;
        }

        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($issues, fn($a, $b) =>
            ($rank[$a['severity'] ?? 'low'] ?? 4) <=> ($rank[$b['severity'] ?? 'low'] ?? 4)
        );

        $shown = 0;
        foreach ($issues as $issue) {
            if ($shown >= 3) {
                $remaining = count($issues) - $shown;
                $this->line("    <fg=gray>… and {$remaining} more issue(s) in this file</>");
                break;
            }
            JustificationCard::render($this->output, $issue);
            $this->newLine();
            $shown++;
        }
    }

    private function printFinalSummary(array $refactorResults, ?array $testResult): void
    {
        $this->newLine();
        $this->info('  ══════════════════════════════════════');
        $this->info('  REFACTORING COMPLETE');
        $this->info('  ══════════════════════════════════════');

        $filesRefactored = count(array_filter($refactorResults, fn($r) => ($r['status'] ?? '') === 'refactored'));
        $this->info("  Files refactored : {$filesRefactored}");

        if ($testResult && ! ($testResult['skipped'] ?? false)) {
            $status = $testResult['passed'] ? '✅  All tests passing' : '❌  Some tests failing';
            $this->info("  Tests            : {$status}");
        }

        $this->newLine();
    }

    private function detectType(): string
    {
        return file_exists($this->projectRoot . '/pubspec.yaml') ? 'flutter' : 'laravel';
    }
}
