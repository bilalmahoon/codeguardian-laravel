<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Support\GitChanges;
use CodeGuardian\Laravel\Support\TestImpact;
use Illuminate\Console\Command;

/**
 * Test impact analysis: given the files changed in git, work out which tests are
 * worth running — and emit a ready-to-use PHPUnit --filter so CI runs a fast,
 * relevant subset instead of the whole suite.
 */
class TestImpactCommand extends Command
{
    protected $signature = 'codeguardian:test-impact
                            {--path=        : Project root (default: base_path())}
                            {--since=       : Diff against this git ref (default: working tree vs HEAD)}
                            {--tests-dir=tests : Directory containing the test suite}
                            {--filter       : Print only the PHPUnit --filter expression (for scripting)}';

    protected $description = 'List the tests impacted by your changes (+ a PHPUnit --filter)';

    public function handle(): int
    {
        $path     = $this->option('path') ?: base_path();
        $repoRoot = GitChanges::repoRoot($path) ?? $path;
        $since    = (string) ($this->option('since') ?: '');

        $changed = $since !== ''
            ? GitChanges::since($repoRoot, $since)
            : GitChanges::workingTree($repoRoot);

        if ($changed === null) {
            $this->error('Could not determine git changes (not a git repo, or git unavailable).');
            return self::FAILURE;
        }

        if ($changed === []) {
            if (! $this->option('filter')) {
                $this->info('  ✅ No changed files — nothing to test.');
            }
            return self::SUCCESS;
        }

        $testFiles = $this->loadTestFiles($path);
        $impacted  = TestImpact::impactedTests($changed, $testFiles);
        $filter    = TestImpact::phpunitFilter($impacted);

        if ($this->option('filter')) {
            $this->line($filter);
            return self::SUCCESS;
        }

        $this->info('🎯 Test impact analysis');
        $this->line('   Changed files: ' . count($changed));
        $this->newLine();

        if ($impacted === []) {
            $this->warn('  No impacted tests found. Consider running the full suite to be safe.');
            return self::SUCCESS;
        }

        $this->info('  Impacted tests (' . count($impacted) . '):');
        foreach ($impacted as $t) {
            $this->line('     • ' . $this->relative($t, $path));
        }

        $this->newLine();
        $this->line('  Run just these:');
        $this->line('    vendor/bin/phpunit --filter="' . $filter . '"');

        return self::SUCCESS;
    }

    /** @return array<string,string> [path => content] */
    private function loadTestFiles(string $path): array
    {
        $dir = rtrim($path, '/\\') . '/' . trim((string) $this->option('tests-dir'), '/\\');
        if (! is_dir($dir)) {
            return [];
        }

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = (string) @file_get_contents($file->getPathname());
            }
        }
        return $files;
    }

    private function relative(string $file, string $root): string
    {
        $root = rtrim($root, '/\\') . '/';
        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }
}
