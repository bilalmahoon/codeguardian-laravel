<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\PackageOrchestrator;
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
                            {--framework=  : Force test framework: phpunit, pest, flutter_test}';

    protected $description = 'Generate test cases for your project using AI (unit, feature, API, widget tests)';

    public function handle(
        CodeScanner         $scanner,
        PackageOrchestrator $orchestrator
    ): int {
        $path      = $this->option('path') ?: base_path();
        $type      = $this->option('type') ?: (file_exists($path . '/pubspec.yaml') ? 'flutter' : 'laravel');
        $outputDir = $this->option('output') ?: base_path(config('codeguardian.output.tests_dir', 'tests/CodeGuardian'));
        $dryRun    = $this->option('dry-run');

        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $this->info('🧪 CodeGuardian AI — Test Generator');
        $this->info("   Scanning: {$path} [{$type}]");
        $this->newLine();

        $context = $scanner->buildContext($path, $type);
        $this->line("   Files: {$context['summary']['total_files']}");
        $this->line('   Generating tests via AI (this may take 30–60 seconds)...');
        $this->newLine();

        $results     = $orchestrator->generateTests($context, function (string $agent, bool $ok) {
            $this->info($ok ? '   ✔  Test generation complete' : '   ✗  QA agent failed');
        });

        $qaResult       = $results['agent_results']['qa'] ?? [];
        $generatedTests = $qaResult['generated_tests'] ?? [];

        if (empty($generatedTests)) {
            $this->warn('   No tests were generated.');
            return self::FAILURE;
        }

        $this->info("   Generated " . count($generatedTests) . " test(s):");
        $this->newLine();

        $saved = 0;
        foreach ($generatedTests as $test) {
            $className = $test['class_name'] ?? 'GeneratedTest' . ($saved + 1);
            $framework = $test['framework'] ?? 'phpunit';
            $testCode  = $test['test_code'] ?? '';
            $scenario  = $test['scenario'] ?? '';
            $coverage  = $test['coverage_area'] ?? '';

            $this->line("  📝 {$className}");
            $this->line("     Scenario : {$scenario}");
            $this->line("     Coverage : {$coverage}");
            $this->line("     Framework: {$framework}");

            if ($dryRun) {
                $this->newLine();
                $this->line('  --- Test Code ---');
                $this->line($testCode);
                $this->line('  -----------------');
            } else {
                $ext      = $framework === 'flutter_test' ? '.dart' : '.php';
                $filePath = $outputDir . '/' . $className . $ext;

                File::ensureDirectoryExists(dirname($filePath));
                File::put($filePath, $testCode);
                $this->info("     ✔  Saved: {$filePath}");
                $saved++;
            }

            $this->newLine();
        }

        if (! $dryRun && $saved > 0) {
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("  ✅  {$saved} test file(s) saved to: {$outputDir}");
            $this->newLine();

            if ($type === 'laravel') {
                $this->info('  Run your tests:');
                $this->line('    php artisan test tests/CodeGuardian/');
                $this->line('    vendor/bin/phpunit tests/CodeGuardian/');
            } else {
                $this->info('  Run your tests:');
                $this->line('    flutter test test/codeguardian/');
            }
        }

        return self::SUCCESS;
    }
}
