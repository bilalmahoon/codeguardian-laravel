<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Analyzers\StaticOrchestrator;
use CodeGuardian\Laravel\Support\CodeScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `codeguardian:fix` — apply only the deterministic, rule-based auto-fixes (no
 * AI, no tokens, no network). Every transformation is syntax-validated before
 * it's accepted, so the engine never writes broken PHP. Use --dry-run to preview.
 *
 * This is the fast, free counterpart to `codeguardian:refactor` (which adds the
 * AI deep-rewrite + test-verification pipeline).
 */
class FixCommand extends Command
{
    protected $signature = 'codeguardian:fix
                            {--path=     : Directory to scan (default: base_path())}
                            {--module=   : Fix a specific module only}
                            {--file=     : Fix a single file}
                            {--dry-run   : Show what would change without writing}
                            {--no-backup : Do not keep a .cgbak backup of changed files}';

    protected $description = 'Apply safe deterministic auto-fixes (no AI) to your code';

    public function handle(CodeScanner $scanner): int
    {
        $path   = (string) ($this->option('path') ?: base_path());
        $dryRun = (bool) $this->option('dry-run');

        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        try {
            $context = match (true) {
                $this->option('file')   => $scanner->buildContextForFile($path, (string) $this->option('file')),
                $this->option('module') => $scanner->buildContextForModule($path, (string) $this->option('module')),
                default                 => $scanner->buildContext($path, 'laravel'),
            };
        } catch (\InvalidArgumentException $e) {
            $this->error('  ' . $e->getMessage());
            return self::FAILURE;
        }

        $files = $context['files'] ?? [];
        if ($files === []) {
            $this->info('  No files to scan.');
            return self::SUCCESS;
        }

        $this->info('🛠  Deterministic auto-fix' . ($dryRun ? ' (dry-run)' : '') . ' — ' . count($files) . ' file(s)');
        $this->newLine();

        $orchestrator = new StaticOrchestrator();
        $raw          = $orchestrator->analyze($files, [], $path);

        // Group findings by file (relative path keys, as the scanner produced).
        $findingsByFile = [];
        foreach ($raw['all_findings'] ?? [] as $f) {
            $file = (string) ($f['file'] ?? '');
            if ($file !== '') {
                $findingsByFile[$file][] = $f;
            }
        }

        $changedFiles = 0;
        $totalFixes   = 0;

        foreach ($files as $relPath => $content) {
            $findings = $findingsByFile[$relPath] ?? [];
            if ($findings === []) {
                continue;
            }

            $result = $orchestrator->refactorFile($relPath, $content, $findings);
            if ($result->autoFixed === 0 || $result->refactored === $content) {
                continue;
            }

            $changedFiles++;
            $totalFixes += $result->autoFixed;

            $this->line("  <fg=green>✎</> {$relPath}  (" . $result->autoFixed . ' fix' . ($result->autoFixed === 1 ? '' : 'es') . ')');
            foreach ($result->changes as $change) {
                if (str_starts_with($change, 'Auto-fixed')) {
                    $this->line('      - ' . $change);
                }
            }

            if (! $dryRun) {
                $full = rtrim($path, '/') . '/' . ltrim($relPath, '/');
                if (! $this->option('no-backup')) {
                    File::put($full . '.cgbak', $content);
                }
                File::put($full, $result->refactored);
            }

            foreach ($result->generatedFiles as $genPath => $genContent) {
                $this->line("      + generated {$genPath}");
                if (! $dryRun) {
                    $genFull = rtrim($path, '/') . '/' . ltrim($genPath, '/');
                    File::ensureDirectoryExists(dirname($genFull));
                    File::put($genFull, $genContent);
                }
            }
        }

        $this->newLine();
        if ($changedFiles === 0) {
            $this->info('  ✅ No deterministic fixes applicable. (Try codeguardian:refactor for AI deep-fixes.)');
            return self::SUCCESS;
        }

        $verb = $dryRun ? 'would fix' : 'fixed';
        $this->info("  ✅ {$verb} {$totalFixes} issue(s) across {$changedFiles} file(s).");
        if ($dryRun) {
            $this->line('  Re-run without --dry-run to apply.');
        } elseif (! $this->option('no-backup')) {
            $this->line('  Backups saved as <file>.cgbak — delete them once you\'ve reviewed the changes.');
        }

        return self::SUCCESS;
    }
}
