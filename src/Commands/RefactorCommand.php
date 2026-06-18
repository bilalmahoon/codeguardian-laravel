<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Analyzers\StaticOrchestrator;
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
    protected $signature = 'codeguardian:refactor
                            {--path=              : Project root directory (default: base_path())}
                            {--module=            : Refactor a specific module only (e.g. User, Order)}
                            {--api=               : Refactor APIs matching this filter (e.g. GET:/api/users)}
                            {--type=              : Project type: laravel or flutter}
                            {--mode=              : Execution mode: interactive (default) or auto}
                            {--no-backup            : Skip creating backups before modifying files}
                            {--skip-tests           : Skip test execution (not recommended)}
                            {--with-existing-tests  : Also run the project existing tests (tests/Feature, tests/Unit) to detect breaking changes}';

    protected $description = 'Analyze → write tests → refactor → verify tests → report (full interactive workflow)';

    private string $projectRoot;
    private string $projectType;
    private bool   $interactiveMode;
    private bool   $backupEnabled;
    private bool   $testsEnabled;
    private bool   $existingTestsEnabled;

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

    public function handle(
        CodeScanner     $scanner,
        ReportFormatter $formatter,
    ): int {
        $this->projectRoot          = $this->option('path') ?: base_path();
        $this->projectType          = $this->option('type') ?: $this->detectType();
        $this->interactiveMode      = ($this->option('mode') ?? 'interactive') === 'interactive';
        $this->backupEnabled        = ! $this->option('no-backup');
        $this->testsEnabled         = ! $this->option('skip-tests');
        // Existing project tests are OPT-IN only — they require a properly
        // configured local environment (DB, queues, .env.testing) and will
        // produce false positives on machines where that isn't set up.
        // Default: only run CodeGuardian-generated stubs (pure unit tests, no env needed).
        $this->existingTestsEnabled = (bool) $this->option('with-existing-tests');
        $this->backups              = [];
        $this->orchestrator         = new StaticOrchestrator();

        if (! is_dir($this->projectRoot)) {
            $this->error("Path does not exist: {$this->projectRoot}");
            return self::FAILURE;
        }

        $this->printBanner();

        // ── Step 1: Determine scope ──────────────────────────────────────────
        $scope    = $this->determineScope();
        $context  = $this->buildContext($scanner, $scope);

        $this->info("  Scope   : {$scope['label']}");
        $this->info("  Files   : {$context['summary']['total_files']}");
        $this->newLine();

        // ── Step 2: Analyze ──────────────────────────────────────────────────
        $this->section('STEP 1/5 — ANALYZING CODE');
        $analysisResults = $this->runAnalysis($context);

        if (empty($analysisResults['agent_results'])) {
            $this->error('Analysis produced no results. Aborting.');
            return self::FAILURE;
        }

        $this->printAnalysisSummary($analysisResults);

        $totalIssues = $analysisResults['summary']['total_issues'] ?? 0;
        if ($totalIssues === 0) {
            $this->info('  ✅  No issues found! Your code is clean.');
            return self::SUCCESS;
        }

        // ── Step 3: Ask to proceed ───────────────────────────────────────────
        if ($this->interactiveMode) {
            if (! $this->confirm("  Proceed with refactoring {$totalIssues} issue(s)?", true)) {
                $this->info('  Refactoring cancelled.');
                return self::SUCCESS;
            }
        }

        // ── Step 4: Generate tests BEFORE any code change ───────────────────
        $this->section('STEP 2/5 — WRITING TESTS (before refactoring)');
        $generatedTestFiles = $this->generateAndWriteTests($analysisResults, $context);

        // ── Step 5: Run baseline tests ───────────────────────────────────────
        $baselineResult = null;
        if ($this->testsEnabled) {
            $this->section('STEP 3/5 — BASELINE TESTS (before any change)');

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
                $this->warn('  Use --skip-existing-tests to skip project tests entirely.');
            }
        }

        // ── Step 6: Refactor files one-by-one ───────────────────────────────
        $this->section('STEP 4/5 — REFACTORING');
        $refactorResults = $this->runRefactoring($analysisResults, $context);

        // ── Step 7: Verify tests pass after refactoring ──────────────────────
        $finalTestResult = null;
        if ($this->testsEnabled) {
            $this->section('STEP 5/5 — FINAL TEST VERIFICATION');
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
            } elseif (! ($finalTestResult['skipped'] ?? false)) {
                $pre = count(array_filter(
                    $finalTestResult['failures'] ?? [],
                    fn($f) => in_array($f['test'] ?? '', $this->baselineFailingTests, true)
                ));
                if ($pre > 0) {
                    $this->line("  ✅  No new failures. ({$pre} pre-existing failure(s) unchanged.)");
                }
            }

            if (! ($finalTestResult['passed'] ?? true)) {
                $this->handleTestFailure();
            }
        }

        // ── Step 8: Generate final report ───────────────────────────────────
        $this->section('GENERATING FINAL REPORT');
        $finalReport = $this->buildFinalReport($analysisResults, $refactorResults, $baselineResult, $finalTestResult);

        $outputDir = storage_path(config('codeguardian.output.report_dir', 'codeguardian/reports'));
        $paths     = $formatter->save($finalReport, $outputDir, 'both');

        $this->newLine();
        $this->info('  📄 Reports saved:');
        foreach ($paths as $p) {
            $this->line("     → {$p}");
        }

        $this->printFinalSummary($refactorResults, $finalTestResult);

        return self::SUCCESS;
    }

    // ─── STEP IMPLEMENTATIONS ────────────────────────────────────────────────

    private function determineScope(): array
    {
        $moduleOpt = $this->option('module');
        $apiOpt    = $this->option('api');

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
        }

        return ['type' => 'full', 'value' => null, 'label' => 'Full project'];
    }

    private function buildContext(CodeScanner $scanner, array $scope): array
    {
        return match ($scope['type']) {
            'module' => $scanner->buildContextForModule($this->projectRoot, $scope['value']),
            'api'    => $scanner->buildContextForApi($this->projectRoot, $scope['value']),
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

    private function runRefactoring(array $analysisResults, array $context): array
    {
        $findingsByFile  = $this->groupFindingsByFile($analysisResults['agent_results']);

        if (empty($findingsByFile)) {
            $this->warn('  No file-specific findings to refactor.');
            return [];
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

            foreach ($issues as $issue) {
                $sev   = strtoupper($issue['severity'] ?? 'medium');
                $title = mb_substr($issue['title'] ?? 'Issue', 0, 80);
                $this->line("    [{$sev}] {$title}");
            }

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
            } else {
                $this->line('  No auto-fixable code patterns found.');
                foreach ($result->changes as $change) {
                    $this->line('  ⚠  ' . $change);
                }
                $refactorResults[$filePath] = [
                    'status' => 'manual_required', 'issues' => count($issues),
                    'auto_fixed' => 0, 'manual_todos' => $result->manualTodos,
                    'changes' => $result->changes,
                ];
                continue;
            }

            // ── Ask confirmation (interactive) or proceed (auto) ─────────────
            if ($this->interactiveMode) {
                if (! $this->confirm("  Apply these changes to {$filePath}?", true)) {
                    $this->line('  Skipped.');
                    $refactorResults[$filePath] = [
                        'status' => 'skipped', 'issues' => count($issues),
                        'auto_fixed' => 0, 'manual_todos' => $result->manualTodos,
                        'changes' => $result->changes,
                    ];
                    continue;
                }
            }

            // ── Final syntax safety gate ─────────────────────────────────────
            // Even though StaticOrchestrator validates each individual fix,
            // the COMBINED result (Phase 1 + Phase 2 comments) is validated here
            // as the absolute last line of defense before any disk write.
            if (! $this->orchestrator->isValidPhp($result->refactored)) {
                $this->error("  ⚠️  SAFETY GATE: refactored file has PHP syntax errors — write ABORTED.");
                $this->error("  Original file is unchanged. Please review manually.");
                $refactorResults[$filePath] = [
                    'status' => 'syntax_error_aborted', 'issues' => count($issues),
                    'auto_fixed' => 0, 'manual_todos' => $result->manualTodos,
                    'changes' => $result->changes,
                ];
                continue;
            }

            // ── Write refactored content to disk ─────────────────────────────
            File::put($fullPath, $result->refactored);
            $this->info("  ✔  Saved ({$result->autoFixed} auto-fix(es), {$result->manualTodos} manual TODO(s))");

            $refactorResults[$filePath] = [
                'status'       => 'refactored',
                'issues'       => count($issues),
                'auto_fixed'   => $result->autoFixed,
                'manual_todos' => $result->manualTodos,
                'changes'      => $result->changes,
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
                } elseif (! ($testResult['skipped'] ?? false)) {
                    // All failures are pre-existing — not our fault
                    $preExisting = count($testResult['failures'] ?? []);
                    if ($preExisting > 0) {
                        $this->line("  ✅  No NEW failures (pre-existing: {$preExisting} ignored).");
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
