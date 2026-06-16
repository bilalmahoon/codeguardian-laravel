<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Agents\QaAgent;
use CodeGuardian\Laravel\Agents\RefactorAgent;
use CodeGuardian\Laravel\PackageOrchestrator;
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
                            {--path=       : Project root directory (default: base_path())}
                            {--module=     : Refactor a specific module only (e.g. User, Order)}
                            {--api=        : Refactor APIs matching this filter (e.g. GET:/api/users)}
                            {--type=       : Project type: laravel or flutter}
                            {--mode=       : Execution mode: interactive (default) or auto}
                            {--no-backup   : Skip creating backups before modifying files}
                            {--skip-tests  : Skip test execution (not recommended)}';

    protected $description = 'Analyze → write tests → refactor → verify tests → report (full interactive workflow)';

    private string $projectRoot;
    private string $projectType;
    private bool   $interactiveMode;
    private bool   $backupEnabled;
    private bool   $testsEnabled;

    /** @var array Rollback map: [ filePath => originalContent ] */
    private array $backups = [];

    public function handle(
        CodeScanner         $scanner,
        PackageOrchestrator $orchestrator,
        ReportFormatter     $formatter,
    ): int {
        $this->projectRoot     = $this->option('path') ?: base_path();
        $this->projectType     = $this->option('type') ?: $this->detectType();
        $this->interactiveMode = ($this->option('mode') ?? 'interactive') === 'interactive';
        $this->backupEnabled   = ! $this->option('no-backup');
        $this->testsEnabled    = ! $this->option('skip-tests');

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
        $analysisResults = $this->runAnalysis($orchestrator, $context);

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
            $this->section('STEP 3/5 — RUNNING BASELINE TESTS');
            $baselineResult = $this->runTests($generatedTestFiles);
            $this->printTestResult('Baseline', $baselineResult);
        }

        // ── Step 6: Refactor files one-by-one ───────────────────────────────
        $this->section('STEP 4/5 — REFACTORING');
        $refactorResults = $this->runRefactoring($analysisResults, $context);

        // ── Step 7: Verify tests pass after refactoring ──────────────────────
        $finalTestResult = null;
        if ($this->testsEnabled && ! empty($generatedTestFiles)) {
            $this->section('STEP 5/5 — VERIFYING TESTS AFTER REFACTORING');
            $finalTestResult = $this->runTests($generatedTestFiles);
            $this->printTestResult('Post-Refactor', $finalTestResult);

            if (! $finalTestResult['passed']) {
                $this->handleTestFailure($generatedTestFiles);
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

    private function runAnalysis(PackageOrchestrator $orchestrator, array $context): array
    {
        $this->line('');
        return $orchestrator->run($context, 'all', function (string $agent, bool $ok, ?string $err) {
            $icon = $ok ? '✔' : '✗';
            $this->line("  {$icon}  {$agent}");
            if (! $ok && $err) {
                $this->warn("      Error: {$err}");
            }
        });
    }

    private function generateAndWriteTests(array $analysisResults, array $context): array
    {
        $testsDir = base_path(config('codeguardian.output.tests_dir', 'tests/CodeGuardian'));
        File::ensureDirectoryExists($testsDir);

        $generatedTests = $analysisResults['agent_results']['qa']['generated_tests'] ?? [];

        if (empty($generatedTests)) {
            // Run QA agent specifically if it wasn't in the analysis
            $this->line('  Running QA agent to generate tests...');
            $allIssues = $this->collectIssues($analysisResults['agent_results']);
            $qaContext = array_merge($context, ['issues' => $allIssues]);
            $qa        = new QaAgent();
            $qaResult  = $qa->analyze($qaContext);
            $generatedTests = $qaResult['generated_tests'] ?? [];
        }

        $writtenFiles = [];
        foreach ($generatedTests as $test) {
            $className = $test['class_name'] ?? ('GeneratedTest' . count($writtenFiles));
            $framework = $test['framework'] ?? 'phpunit';
            $ext       = $framework === 'flutter_test' ? '.dart' : '.php';
            $filePath  = $testsDir . '/' . $className . $ext;
            $testCode  = $test['test_code'] ?? '';

            if (empty($testCode)) {
                continue;
            }

            File::put($filePath, $testCode);
            $writtenFiles[] = $filePath;
            $this->info("  ✔  Test written: tests/CodeGuardian/{$className}{$ext}");
        }

        if (empty($writtenFiles)) {
            $this->warn('  No tests were generated. Continuing without tests.');
        } else {
            $this->info('  ' . count($writtenFiles) . ' test file(s) written to tests/CodeGuardian/');
        }

        return $writtenFiles;
    }

    private function runTests(array $testFiles): array
    {
        if (! $this->testsEnabled || empty($testFiles)) {
            return ['passed' => true, 'total' => 0, 'skipped' => true];
        }

        $runner  = new TestRunner($this->projectRoot);

        if (! $runner->isAvailable($this->projectType)) {
            $this->warn('  PHPUnit/Pest not found. Skipping test run.');
            return ['passed' => true, 'total' => 0, 'skipped' => true];
        }

        $testsDir = base_path(config('codeguardian.output.tests_dir', 'tests/CodeGuardian'));
        $result   = $runner->run($testsDir, $this->projectType);

        return $result;
    }

    private function runRefactoring(array $analysisResults, array $context): array
    {
        // Group findings by file
        $findingsByFile = $this->groupFindingsByFile($analysisResults['agent_results']);

        if (empty($findingsByFile)) {
            $this->warn('  No file-specific findings to refactor.');
            return [];
        }

        $refactorAgent   = new RefactorAgent();
        $refactorResults = [];
        $fileCount       = 0;
        $totalFiles      = count($findingsByFile);

        foreach ($findingsByFile as $filePath => $issues) {
            $fileCount++;
            $this->newLine();
            $this->info("  [{$fileCount}/{$totalFiles}] {$filePath}");
            $this->line("  Issues: " . count($issues));

            // Show issues for this file
            foreach ($issues as $issue) {
                $sev   = strtoupper($issue['severity'] ?? 'medium');
                $title = $issue['title'] ?? 'Issue';
                $this->line("    [{$sev}] {$title}");
            }

            // Ask confirmation in interactive mode
            if ($this->interactiveMode) {
                if (! $this->confirm("  Refactor this file?", true)) {
                    $this->line('  Skipped.');
                    continue;
                }
            }

            // Read current file content
            $fullPath = $this->projectRoot . '/' . ltrim($filePath, '/');
            if (! file_exists($fullPath)) {
                $this->warn("  File not found: {$fullPath} — skipping.");
                continue;
            }

            $originalContent = file_get_contents($fullPath);

            // Backup
            if ($this->backupEnabled) {
                $this->backups[$filePath] = $originalContent;
            }

            // Run RefactorAgent
            $this->line('  Refactoring via AI...');

            try {
                $result = $refactorAgent->refactorFile($filePath, $originalContent, $issues);
            } catch (\Throwable $e) {
                $this->error("  AI refactoring failed: {$e->getMessage()}");
                continue;
            }

            $refactoredContent = $result['refactored_file'] ?? null;

            if (empty($refactoredContent)) {
                $this->warn('  AI did not return refactored content. Skipping.');
                continue;
            }

            // Write refactored file
            File::put($fullPath, $refactoredContent);
            $this->info("  ✔  File updated: {$filePath}");

            // Show changes summary
            $changes = $result['changes'] ?? [];
            foreach ($changes as $change) {
                $this->line("     → {$change['type']}: {$change['description']}");
            }

            $refactorResults[$filePath] = [
                'status'   => 'refactored',
                'issues'   => count($issues),
                'changes'  => $changes,
            ];

            // Run tests after each file refactoring (one-by-one mode)
            if ($this->testsEnabled && $this->interactiveMode) {
                $testsDir = base_path(config('codeguardian.output.tests_dir', 'tests/CodeGuardian'));
                if (is_dir($testsDir)) {
                    $this->line('  Running tests to verify...');
                    $runner      = new TestRunner($this->projectRoot);
                    $testResult  = $runner->run($testsDir, $this->projectType);

                    $this->printTestResult("After {$filePath}", $testResult);

                    if (! $testResult['passed']) {
                        $this->error("  ⚠️  Tests failed after refactoring {$filePath}!");
                        $choice = $this->choice(
                            'What do you want to do?',
                            ['Rollback this file', 'Continue anyway', 'Stop refactoring'],
                            0
                        );

                        match ($choice) {
                            'Rollback this file' => $this->rollbackFile($filePath, $fullPath),
                            'Stop refactoring'   => $this->stopRefactoring($refactorResults),
                            default              => null,
                        };

                        if ($choice === 'Stop refactoring') {
                            return $refactorResults;
                        }
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

    private function collectIssues(array $agentResults): array
    {
        $issues = [];
        foreach ($agentResults as $result) {
            foreach ($result['findings'] ?? [] as $f) {
                $issues[] = $f;
            }
        }
        return $issues;
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

    private function stopRefactoring(array &$results): void
    {
        $this->warn('  Stopping refactoring. Rolling back all changed files...');
        foreach ($this->backups as $filePath => $originalContent) {
            $fullPath = $this->projectRoot . '/' . ltrim($filePath, '/');
            File::put($fullPath, $originalContent);
            $this->line("  ↩  Rolled back: {$filePath}");
        }
        $results['stopped'] = true;
    }

    private function handleTestFailure(array $testFiles): void
    {
        $this->newLine();
        $this->error('  ⚠️  Tests failed after refactoring!');

        if ($this->interactiveMode) {
            $choice = $this->choice(
                'Tests are failing. What do you want to do?',
                ['Rollback all changes', 'Keep changes and review manually', 'Ignore and continue'],
                0
            );

            if ($choice === 'Rollback all changes') {
                foreach ($this->backups as $filePath => $original) {
                    $fullPath = $this->projectRoot . '/' . ltrim($filePath, '/');
                    File::put($fullPath, $original);
                    $this->line("  ↩  Rolled back: {$filePath}");
                }
                $this->info('  All changes rolled back.');
            }
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
        $summary  = $results['summary'] ?? [];
        $scores   = $results['scores'] ?? [];
        $overall  = $results['overall_score'] ?? 'N/A';

        $this->newLine();
        $this->info("  Overall Score : {$overall}/100");
        foreach ($scores as $key => $val) {
            $label = ucwords(str_replace('_score', '', str_replace('_', ' ', $key)));
            $color = $val >= 80 ? 'info' : ($val >= 60 ? 'warn' : 'error');
            $this->{$color}("  {$label} : {$val}/100");
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

    private function printTestResult(string $label, array $result): void
    {
        if ($result['skipped'] ?? false) {
            $this->line("  [{$label}] Tests skipped.");
            return;
        }

        $icon    = $result['passed'] ? '✅' : '❌';
        $total   = $result['total'] ?? 0;
        $passed  = $result['passed_count'] ?? 0;
        $failed  = $result['failed_count'] ?? 0;
        $ms      = $result['duration_ms'] ?? 0;

        $this->line("  {$icon} {$label}: {$passed}/{$total} passed, {$failed} failed ({$ms}ms)");

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
