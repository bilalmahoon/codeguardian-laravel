<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Analyzers\StaticOrchestrator;
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
                            {--file=       : Generate test for a single file only}';

    protected $description = 'Generate PHPUnit test stubs from your code signatures — no API key needed';

    public function handle(CodeScanner $scanner): int
    {
        $path      = $this->option('path') ?: base_path();
        $type      = $this->option('type') ?: (file_exists($path . '/pubspec.yaml') ? 'flutter' : 'laravel');
        $outputDir = $this->option('output') ?: base_path(config('codeguardian.output.tests_dir', 'tests/CodeGuardian'));
        $dryRun    = $this->option('dry-run');
        $singleFile = $this->option('file');

        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $this->info('🧪 CodeGuardian — Test Generator  (static engine, no API key needed)');
        $this->info("   Scanning: {$path} [{$type}]");
        $this->newLine();

        $context = $scanner->buildContext($path, $type);
        $files   = $context['files'] ?? [];

        // Single-file mode
        if ($singleFile) {
            $files = array_filter(
                $files,
                fn($path) => str_contains($path, $singleFile) || basename($path) === $singleFile,
                ARRAY_FILTER_USE_KEY
            );
        }

        $this->line("   Files: " . count($files));
        $this->line("   Analysing method signatures...");
        $this->newLine();

        $orchestrator = new StaticOrchestrator();
        $tests        = $orchestrator->generateTests($files);

        if (empty($tests)) {
            $this->warn('   No test stubs could be generated (no public methods found or only test files exist).');
            return self::FAILURE;
        }

        $this->info("   Generated " . count($tests) . " test stub(s):");
        $this->newLine();

        $saved = 0;
        foreach ($tests as $test) {
            $this->line("  📝 {$test->className}");
            $this->line("     Source  : " . basename($test->sourceFile));
            $this->line("     Methods : " . implode(', ', $test->methodsCovered));
            $this->line("     Target  : {$test->filePath}");

            if ($dryRun) {
                $this->newLine();
                $this->line('  --- Generated Test ---');
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
            $this->info("  ✅  {$saved} test file(s) saved to: {$outputDir}");
            $this->newLine();
            $this->info('  Next steps:');
            $this->line('  1. Review generated tests and fill in proper test data');
            $this->line('  2. Run: php artisan test tests/CodeGuardian/');
            $this->line('     or:  ./vendor/bin/phpunit tests/CodeGuardian/');
        }

        return self::SUCCESS;
    }
}
