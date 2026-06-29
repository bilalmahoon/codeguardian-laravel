<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `codeguardian:init` — one-command onboarding. Publishes the config, writes a
 * ready-to-use CI workflow, and (optionally) installs a git pre-commit hook so
 * a new project is productive in seconds.
 */
class InitCommand extends Command
{
    protected $signature = 'codeguardian:init
                            {--dir=       : Target project directory (default: base_path())}
                            {--ci=        : CI workflow to scaffold: github | gitlab | none (auto-detected)}
                            {--hook       : Install a git pre-commit hook that runs a fast incremental scan}
                            {--force      : Overwrite existing files}';

    protected $description = 'Scaffold CodeGuardian config, CI workflow, and an optional git hook';

    public function handle(): int
    {
        $dir = rtrim((string) ($this->option('dir') ?: base_path()), '/');
        $this->info('🛡  Initialising CodeGuardian in: ' . $dir);
        $this->newLine();

        $this->publishConfig($dir);
        $this->scaffoldCi($dir, $this->resolveCi($dir));

        if ($this->option('hook')) {
            $this->installGitHook($dir);
        }

        $this->newLine();
        $this->info('✅ Done. Next steps:');
        $this->line('   • Review config/codeguardian.php (set CODEGUARDIAN_CLAUDE_KEY for AI mode)');
        $this->line('   • Run: php artisan codeguardian:doctor');
        $this->line('   • Run: php artisan codeguardian:analyze');

        return self::SUCCESS;
    }

    private function publishConfig(string $dir): void
    {
        $source = dirname(__DIR__, 2) . '/config/codeguardian.php';
        $dest   = $dir . '/config/codeguardian.php';

        if (! is_file($source)) {
            $this->warn('  ⚠ Package config not found; skipping config publish.');
            return;
        }
        if (is_file($dest) && ! $this->option('force')) {
            $this->line('  • config/codeguardian.php already exists (use --force to overwrite).');
            return;
        }

        File::ensureDirectoryExists(dirname($dest));
        File::copy($source, $dest);
        $this->info('  ✔ Published config/codeguardian.php');
    }

    private function resolveCi(string $dir): string
    {
        $ci = strtolower((string) ($this->option('ci') ?: ''));
        if (in_array($ci, ['github', 'gitlab', 'none'], true)) {
            return $ci;
        }
        // Auto-detect from existing CI conventions.
        if (is_dir($dir . '/.github')) {
            return 'github';
        }
        if (is_file($dir . '/.gitlab-ci.yml')) {
            return 'gitlab';
        }
        return 'github'; // sensible default
    }

    private function scaffoldCi(string $dir, string $ci): void
    {
        if ($ci === 'none') {
            $this->line('  • Skipping CI workflow.');
            return;
        }

        if ($ci === 'gitlab') {
            $this->writeFile($dir . '/codeguardian.gitlab-ci.yml', $this->gitlabTemplate(),
                'codeguardian.gitlab-ci.yml (include it from your .gitlab-ci.yml)');
            return;
        }

        $this->writeFile($dir . '/.github/workflows/codeguardian.yml', $this->githubTemplate(),
            '.github/workflows/codeguardian.yml');
    }

    private function installGitHook(string $dir): void
    {
        $hooksDir = $dir . '/.git/hooks';
        if (! is_dir($hooksDir)) {
            $this->warn('  ⚠ No .git/hooks directory (not a git repo?); skipping hook.');
            return;
        }

        $hookPath = $hooksDir . '/pre-commit';
        if (is_file($hookPath) && ! $this->option('force')) {
            $this->line('  • pre-commit hook already exists (use --force to overwrite).');
            return;
        }

        $hook = <<<SH
        #!/bin/sh
        # CodeGuardian pre-commit: fast incremental scan of staged changes.
        php artisan codeguardian:analyze --changed --plain --fail-on=high --no-report || {
            echo "CodeGuardian found high/critical issues. Commit aborted (use 'git commit --no-verify' to bypass).";
            exit 1;
        }
        SH;

        File::put($hookPath, $hook);
        @chmod($hookPath, 0755);
        $this->info('  ✔ Installed .git/hooks/pre-commit');
    }

    private function writeFile(string $path, string $contents, string $label): void
    {
        if (is_file($path) && ! $this->option('force')) {
            $this->line("  • {$label} already exists (use --force to overwrite).");
            return;
        }
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
        $this->info("  ✔ Wrote {$label}");
    }

    private function githubTemplate(): string
    {
        return <<<'YAML'
        name: CodeGuardian
        on:
          pull_request:
          push:
            branches: [main, master]

        jobs:
          codeguardian:
            runs-on: ubuntu-latest
            steps:
              - uses: actions/checkout@v4
                with:
                  fetch-depth: 0
              - uses: shivammathur/setup-php@v2
                with:
                  php-version: '8.2'
              - run: composer install --no-interaction --prefer-dist
              - name: Analyze (only changed files on PRs)
                run: |
                  if [ "${{ github.event_name }}" = "pull_request" ]; then
                    php artisan codeguardian:analyze --since=origin/${{ github.base_ref }} --format=sarif --annotate --plain --fail-on=high
                  else
                    php artisan codeguardian:analyze --format=sarif --plain
                  fi
              - name: Upload SARIF
                if: always()
                uses: github/codeql-action/upload-sarif@v3
                with:
                  sarif_file: storage/codeguardian/reports
        YAML;
    }

    private function gitlabTemplate(): string
    {
        return <<<'YAML'
        # Include this from your .gitlab-ci.yml:  include: 'codeguardian.gitlab-ci.yml'
        codeguardian:
          image: php:8.2
          script:
            - composer install --no-interaction --prefer-dist
            - php artisan codeguardian:analyze --format=junit --plain --fail-on=high
          artifacts:
            when: always
            reports:
              junit: storage/codeguardian/reports/*.junit.xml
            paths:
              - storage/codeguardian/reports
        YAML;
    }
}
