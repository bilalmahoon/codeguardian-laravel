<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Agents\QaAgent;
use CodeGuardian\Laravel\Analyzers\StaticOrchestrator;
use CodeGuardian\Laravel\Support\AiClient;
use CodeGuardian\Laravel\Support\CodeScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateTestsCommand extends Command
{
    protected $signature = 'codeguardian:test
                            {--path=       : Directory to analyze (default: base_path())}
                            {--type=       : Project type: laravel or flutter}
                            {--output=     : Where to save generated tests (default: tests/CodeGuardian/)}
                            {--dry-run     : Show generated test code in console, do not write files}
                            {--file=       : Generate test for a single file only}
                            {--mode=       : Engine mode: static (stubs only) | ai | hybrid (auto-detected)}';

    protected $description = 'Senior QA test generation — real tests with assertions, mocks, edge cases, auth tests via Claude AI';

    public function handle(CodeScanner $scanner): int
    {
        $path      = $this->option('path') ?: base_path();
        $type      = $this->option('type') ?: (file_exists($path . '/pubspec.yaml') ? 'flutter' : 'laravel');
        $outputDir = $this->option('output') ?: base_path(config('codeguardian.output.tests_dir', 'tests/CodeGuardian'));
        $dryRun    = $this->option('dry-run');
        $singleFile = $this->option('file');
        $mode      = $this->resolveMode();

        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $engineLabel = match ($mode) {
            'ai', 'hybrid' => '🤖 Claude AI — Senior QA (real tests, real assertions)',
            default         => '⚡ Static engine (test stubs only)',
        };

        $this->info("🧪 CodeGuardian — Test Generator");
        $this->info("   Scanning: {$path} [{$type}]");
        $this->info("   Engine: {$engineLabel}");
        $this->newLine();

        $context = $scanner->buildContext($path, $type);
        $files   = $context['files'] ?? [];

        // Single-file mode
        if ($singleFile) {
            $files = array_filter(
                $files,
                fn($fPath) => str_contains($fPath, $singleFile) || basename($fPath) === $singleFile,
                ARRAY_FILTER_USE_KEY
            );
        }

        $this->line("   Files: " . count($files));
        $this->newLine();

        // Choose path: AI generates real tests, static generates stubs
        if (in_array($mode, ['ai', 'hybrid'])) {
            return $this->runAiTestGeneration($context, $files, $outputDir, $dryRun);
        }

        return $this->runStaticTestGeneration($files, $outputDir, $dryRun);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AI test generation — Senior QA level real tests
    // ──────────────────────────────────────────────────────────────────────────

    private function runAiTestGeneration(array $context, array $files, string $outputDir, bool $dryRun): int
    {
        $this->line('  Running static analysis to gather issue context...');
        $orchestrator = new StaticOrchestrator();
        $staticResult = $orchestrator->analyze($files, [
            'architecture' => true,
            'security'     => true,
            'performance'  => true,
            'tech_debt'    => false,
        ], '');

        $issues = $staticResult['all_findings'] ?? [];
        $this->line("  Found " . count($issues) . " issues to write tests for.");

        // Limit files sent to AI — prioritize files with known issues
        $maxFiles     = (int) config('codeguardian.analysis.ai_hotspot_limit', 10);
        $hotspotFiles = $this->getHotspotFiles($files, $issues, $maxFiles);

        $this->line("  Sending " . count($hotspotFiles) . " hotspot file(s) to Claude QA agent...");
        $this->newLine();

        $qaContext = array_merge($context, [
            'files'   => $hotspotFiles,
            'issues'  => $issues,
            'summary' => [
                'total_files' => count($hotspotFiles),
                'total_lines' => array_sum(array_map(fn($c) => substr_count($c, "\n") + 1, $hotspotFiles)),
            ],
        ]);

        $agent = new QaAgent();

        try {
            $result = $agent->analyze($qaContext);
        } catch (\Throwable $e) {
            $this->warn("  ⚠  Claude QA agent failed: {$e->getMessage()}");
            $this->warn("     Falling back to static stub generation...");
            return $this->runStaticTestGeneration($files, $outputDir, $dryRun);
        }

        $generatedTests = $result['generated_tests'] ?? [];

        if (empty($generatedTests)) {
            $this->warn('  No tests generated. Falling back to static stub generation...');
            return $this->runStaticTestGeneration($files, $outputDir, $dryRun);
        }

        $this->info("  Generated " . count($generatedTests) . " test class(es) by Claude QA agent:");
        $this->newLine();

        $saved = 0;
        foreach ($generatedTests as $test) {
            $className = $test['class_name'] ?? 'GeneratedTest';
            $filePath  = $test['file_path'] ?? "tests/CodeGuardian/{$className}.php";
            $testCode  = $test['test_code'] ?? '';
            $scenario  = $test['scenario'] ?? '';

            // Enforce tests/CodeGuardian/ prefix
            if (! str_starts_with($filePath, 'tests/CodeGuardian')) {
                $filePath = 'tests/CodeGuardian/' . basename($filePath);
            }

            $this->line("  📝 {$className}");
            if ($scenario) $this->line("     Scenario : {$scenario}");
            $this->line("     Coverage : " . ($test['coverage_area'] ?? 'N/A'));
            $this->line("     Target   : {$filePath}");

            if ($dryRun) {
                $this->newLine();
                $this->line('  --- Generated Test (Claude QA) ---');
                $this->line($testCode);
                $this->line('  -----------------------------------');
            } elseif ($testCode) {
                $fullPath = base_path($filePath);
                File::ensureDirectoryExists(dirname($fullPath));

                if (File::exists($fullPath)) {
                    if (! $this->confirm("     ⚠  {$filePath} already exists. Overwrite?", false)) {
                        $this->line("     Skipped.");
                        $this->newLine();
                        continue;
                    }
                }

                File::put($fullPath, $testCode);
                $this->info("     ✔  Saved: {$fullPath}");
                $saved++;
            }

            $this->newLine();
        }

        if (! $dryRun && $saved > 0) {
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("  ✅  {$saved} test file(s) saved to: {$outputDir}");
            $this->newLine();
            $this->info('  Run: php artisan test tests/CodeGuardian/');
        }

        return self::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Static stub generation (fallback — no API key)
    // ──────────────────────────────────────────────────────────────────────────

    private function runStaticTestGeneration(array $files, string $outputDir, bool $dryRun): int
    {
        $this->line('  Analysing method signatures (static stubs)...');
        $this->newLine();

        $orchestrator = new StaticOrchestrator();
        $tests        = $orchestrator->generateTests($files);

        if (empty($tests)) {
            $this->warn('  No test stubs could be generated (no public methods found or only test files exist).');
            return self::FAILURE;
        }

        $this->info("  Generated " . count($tests) . " test stub(s):");
        $this->newLine();

        $saved = 0;
        foreach ($tests as $test) {
            $this->line("  📝 {$test->className}");
            $this->line("     Source  : " . basename($test->sourceFile));
            $this->line("     Methods : " . implode(', ', $test->methodsCovered));
            $this->line("     Target  : {$test->filePath}");

            if ($dryRun) {
                $this->newLine();
                $this->line('  --- Generated Stub ---');
                $this->line($test->content);
                $this->line('  ----------------------');
            } else {
                $fullPath = base_path($test->filePath);
                File::ensureDirectoryExists(dirname($fullPath));

                if (File::exists($fullPath)) {
                    if (! $this->confirm("     ⚠  {$test->filePath} already exists. Overwrite?", false)) {
                        $this->line("     Skipped.");
                        $this->newLine();
                        continue;
                    }
                }

                File::put($fullPath, $test->content);
                $this->info("     ✔  Saved: {$fullPath}");
                $saved++;
            }

            $this->newLine();
        }

        if (! $dryRun && $saved > 0) {
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("  ✅  {$saved} stub file(s) saved to: {$outputDir}");
            $this->newLine();
            $this->info('  Note: stubs have TODO placeholders — fill in test data and run:');
            $this->line('  php artisan test tests/CodeGuardian/');
        }

        return self::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function resolveMode(): string
    {
        // An explicit --mode is authoritative and is NEVER auto-upgraded, so
        // --mode=static stays static (no AI calls, no cost) even with a key set.
        $optMode = $this->option('mode');
        if (is_string($optMode) && trim($optMode) !== '') {
            return $this->normalizeMode($optMode);
        }

        $configMode = $this->normalizeMode((string) config('codeguardian.mode', 'static'));
        if ($configMode !== 'static') {
            return $configMode;
        }

        return AiClient::hasApiKey() ? 'hybrid' : 'static';
    }

    /** Whitelist a mode string; anything unknown falls back to static. */
    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['static', 'hybrid', 'ai'], true) ? $mode : 'static';
    }

    /** Returns files that have known issues, limited to $limit. Falls back to all files. */
    private function getHotspotFiles(array $files, array $issues, int $limit): array
    {
        if (empty($issues)) {
            return array_slice($files, 0, $limit, true);
        }

        $issuePaths = array_unique(array_filter(array_map(
            fn($f) => $f['file'] ?? null,
            $issues
        )));

        $hotspot = [];
        foreach ($issuePaths as $issuePath) {
            foreach ($files as $filePath => $content) {
                if (str_ends_with($filePath, $issuePath) || $filePath === $issuePath) {
                    $hotspot[$filePath] = $content;
                }
            }
            if (count($hotspot) >= $limit) break;
        }

        return $hotspot ?: array_slice($files, 0, $limit, true);
    }
}
