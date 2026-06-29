<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Support\AiClient;
use CodeGuardian\Laravel\Support\Diagnostics;
use CodeGuardian\Laravel\Support\ModuleDetector;
use CodeGuardian\Laravel\Support\TestRunner;
use Illuminate\Console\Command;

/**
 * `codeguardian:doctor` — environment & configuration health check.
 *
 * Verifies everything CodeGuardian needs (PHP version, extensions, AI config,
 * writable paths, test runner, dashboard safety) and prints actionable recovery
 * suggestions for anything that isn't right. Returns a non-zero exit code when
 * any hard check fails, so it can gate CI.
 */
class DoctorCommand extends Command
{
    protected $signature = 'codeguardian:doctor
                            {--path=  : Project root directory (default: base_path())}
                            {--json   : Output machine-readable JSON}';

    protected $description = 'Diagnose your CodeGuardian setup and suggest fixes';

    public function handle(): int
    {
        $root   = $this->option('path') ?: base_path();
        $env    = $this->gatherEnvironment($root);
        $checks = Diagnostics::evaluate($env);
        $counts = Diagnostics::summarize($checks);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'checks'  => $checks,
                'summary' => $counts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $counts[Diagnostics::FAIL] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->renderReport($checks, $counts);

        return $counts[Diagnostics::FAIL] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function gatherEnvironment(string $root): array
    {
        $mode     = (string) config('codeguardian.mode', 'static');
        $provider = (string) config('codeguardian.provider', 'openai');

        $reportDir = storage_path((string) config('codeguardian.output.report_dir', 'codeguardian/reports'));
        $runsDir   = (string) config('codeguardian.dashboard.runs_dir', storage_path('codeguardian/runs'));
        $testsDir  = base_path((string) config('codeguardian.output.tests_dir', 'tests/CodeGuardian'));

        $modules = null;
        try {
            $detector = new ModuleDetector($root);
            $modules  = $detector->isModular() ? $detector->detectStructureType() : 'none';
        } catch (\Throwable) {
            $modules = 'none';
        }

        $phpunit = false;
        try {
            $phpunit = (new TestRunner($root))->isAvailable('laravel');
        } catch (\Throwable) {
            $phpunit = false;
        }

        return [
            'php_version'          => PHP_VERSION,
            'extensions'           => get_loaded_extensions(),
            'mode'                 => $mode,
            'provider'             => $provider,
            'has_api_key'          => $this->hasApiKey($provider),
            'writable'             => [
                'reports' => ['path' => $reportDir, 'writable' => $this->isWritable($reportDir)],
                'runs'    => ['path' => $runsDir,   'writable' => $this->isWritable($runsDir)],
                'tests'   => ['path' => $testsDir,  'writable' => $this->isWritable($testsDir)],
            ],
            'phpunit_available'    => $phpunit,
            'config_published'     => file_exists(config_path('codeguardian.php')),
            'dashboard_enabled'    => (bool) config('codeguardian.dashboard.enabled', true),
            'dashboard_local_only' => (bool) config('codeguardian.dashboard.restrict_to_local', true),
            'dashboard_middleware' => (array) config('codeguardian.dashboard.middleware', ['web']),
            'app_env'              => (string) $this->laravel->environment(),
            'modules_detected'     => $modules,
        ];
    }

    private function hasApiKey(string $provider): bool
    {
        $keyMap  = ['claude' => 'claude.key', 'openai' => 'openai.key', 'gemini' => 'gemini.key'];
        $keyPath = $keyMap[$provider] ?? 'openai.key';
        if (! empty(config("codeguardian.{$keyPath}"))) {
            return true;
        }

        // Fall back to the client's own detection where available.
        try {
            return AiClient::hasApiKey();
        } catch (\Throwable) {
            return false;
        }
    }

    /** A path is "writable" if it exists and is writable, or its nearest existing parent is. */
    private function isWritable(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        $parent = dirname($path);
        $guard  = 0;
        while ($parent !== '' && $parent !== '/' && $parent !== '.' && ! is_dir($parent) && $guard++ < 20) {
            $parent = dirname($parent);
        }

        return is_dir($parent) && is_writable($parent);
    }

    /**
     * @param array<int,array<string,string>> $checks
     * @param array{pass:int,warn:int,fail:int} $counts
     */
    private function renderReport(array $checks, array $counts): void
    {
        $this->newLine();
        $this->info('  ╔══════════════════════════════════════════════════╗');
        $this->info('  ║            CodeGuardian AI — Doctor              ║');
        $this->info('  ╚══════════════════════════════════════════════════╝');
        $this->newLine();

        foreach ($checks as $c) {
            [$icon, $method] = match ($c['status']) {
                Diagnostics::PASS => ['✓', 'info'],
                Diagnostics::WARN => ['⚠', 'warn'],
                default           => ['✗', 'error'],
            };

            $label = str_pad((string) $c['label'], 22);
            $this->{$method}("  {$icon} {$label} {$c['message']}");

            if (! empty($c['fix']) && $c['status'] !== Diagnostics::PASS) {
                $this->line("      ↳ {$c['fix']}");
            }
        }

        $this->newLine();
        $this->info('  ──────────────────────────────────────────────────');
        $this->line(sprintf(
            '  %d passed · %d warning(s) · %d failure(s)',
            $counts[Diagnostics::PASS],
            $counts[Diagnostics::WARN],
            $counts[Diagnostics::FAIL]
        ));

        if ($counts[Diagnostics::FAIL] > 0) {
            $this->error('  ✗ Some checks failed — resolve the items above.');
        } elseif ($counts[Diagnostics::WARN] > 0) {
            $this->warn('  ⚠ Healthy, with a few recommendations.');
        } else {
            $this->info('  ✓ All systems go. CodeGuardian is ready.');
        }
        $this->newLine();
    }
}
