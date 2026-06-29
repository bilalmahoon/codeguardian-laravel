<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Analyzers\Severity;
use CodeGuardian\Laravel\Support\DependencyAudit;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Scans Composer dependencies for known security advisories and abandoned
 * packages. Prefers the offline `composer audit` (uses the bundled advisory
 * database); falls back to the Packagist advisories API when Composer is not
 * available on the host.
 */
class AuditCommand extends Command
{
    protected $signature = 'codeguardian:audit
                            {--path=     : Project root containing composer.lock (default: base_path())}
                            {--format=   : Save a report in this format (json|html|md|sarif) in addition to console}
                            {--output=   : Output directory for the saved report}
                            {--fail-on=  : Exit non-zero if any advisory is at or above this severity}';

    protected $description = 'Audit Composer dependencies for known vulnerabilities and abandoned packages';

    public function handle(): int
    {
        $path     = $this->option('path') ?: base_path();
        $lockPath = rtrim($path, '/\\') . '/composer.lock';

        $this->info('🔒 CodeGuardian dependency audit');
        $this->newLine();

        if (! is_file($lockPath)) {
            $this->error("  composer.lock not found at: {$lockPath}");
            $this->line('  Run `composer install` first, or pass --path=.');
            return self::FAILURE;
        }

        $versions = DependencyAudit::parseLock((string) file_get_contents($lockPath));
        $this->line('  Locked packages: ' . count($versions));

        $findings = $this->auditViaComposer($path, $versions);
        if ($findings === null) {
            $this->line('  composer audit unavailable — querying Packagist advisories...');
            $findings = $this->auditViaPackagist($versions);
        }

        if ($findings === null) {
            $this->warn('  ⚠ Could not complete the audit (no composer binary and no network).');
            return self::FAILURE;
        }

        $results = DependencyAudit::toResult($findings, count($versions), basename($path));
        $this->printSummary($results);

        if ($findings !== [] && $this->option('format')) {
            $this->saveReport($results);
        }

        $failOn = (string) ($this->option('fail-on') ?: '');
        if ($failOn !== '') {
            foreach ($findings as $f) {
                if (Severity::atLeast((string) ($f['severity'] ?? 'low'), strtolower(trim($failOn)))) {
                    return self::FAILURE;
                }
            }
            return self::SUCCESS;
        }

        $critical = $results['summary']['critical'] ?? 0;
        return $critical > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string,string> $versions
     * @return list<array<string,mixed>>|null  null when composer is unavailable
     */
    private function auditViaComposer(string $path, array $versions): ?array
    {
        $composer = $this->findComposer();
        if ($composer === null) {
            return null;
        }

        $process = new Process([...$composer, 'audit', '--locked', '--format=json'], $path);
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return null;
        }

        $out = trim($process->getOutput());
        if ($out === '') {
            // composer audit exits non-zero with findings; output may be on stdout still.
            $out = trim($process->getErrorOutput());
        }

        $json = json_decode($out, true);
        if (! is_array($json)) {
            return null;
        }

        return DependencyAudit::fromComposerAudit($json, $versions);
    }

    /**
     * @param  array<string,string> $versions
     * @return list<array<string,mixed>>|null  null on network failure
     */
    private function auditViaPackagist(array $versions): ?array
    {
        if ($versions === []) {
            return [];
        }

        $client = new Client(['timeout' => 60]);
        $query  = [];
        foreach (array_keys($versions) as $name) {
            $query[] = 'packages[]=' . rawurlencode($name);
        }

        try {
            $response = $client->get(
                'https://packagist.org/api/security-advisories/?' . implode('&', $query)
            );
            $json = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            return null;
        }

        if (! is_array($json)) {
            return null;
        }

        return DependencyAudit::fromPackagist($json, $versions);
    }

    /** @return list<string>|null composer invocation (binary or `php composer.phar`) */
    private function findComposer(): ?array
    {
        foreach (['composer', '/usr/local/bin/composer', '/usr/bin/composer'] as $candidate) {
            $probe = new Process([$candidate, '--version']);
            try {
                $probe->run();
                if ($probe->isSuccessful()) {
                    return [$candidate];
                }
            } catch (\Throwable) {
                // try next candidate
            }
        }
        return null;
    }

    private function printSummary(array $results): void
    {
        $summary = $results['summary'];
        $total   = $summary['total_issues'] ?? 0;

        $this->newLine();
        if ($total === 0) {
            $this->info('  ✅ No known vulnerabilities or abandoned packages found.');
            return;
        }

        $this->error("  Found {$total} dependency issue(s):");
        if (($summary['critical'] ?? 0) > 0) $this->error("    🔴 Critical: {$summary['critical']}");
        if (($summary['high'] ?? 0) > 0)     $this->warn("    🟠 High:     {$summary['high']}");
        if (($summary['medium'] ?? 0) > 0)   $this->line("    🟡 Medium:   {$summary['medium']}");
        if (($summary['low'] ?? 0) > 0)      $this->line("    🟢 Low:      {$summary['low']}");

        $this->newLine();
        foreach ($results['all_findings'] as $f) {
            $sev = strtoupper((string) ($f['severity'] ?? 'low'));
            $this->line("  [{$sev}] " . ($f['title'] ?? ''));
            if (! empty($f['recommendation'])) {
                $this->line('         → ' . $f['recommendation']);
            }
        }
    }

    private function saveReport(array $results): void
    {
        $formatter = app(\CodeGuardian\Laravel\Support\ReportFormatter::class);
        $outputDir = $this->option('output')
            ?: storage_path(config('codeguardian.output.report_dir', 'codeguardian/reports'));

        $paths = $formatter->save($results, $outputDir, (string) $this->option('format'));
        $this->newLine();
        $this->info('  📄 Report saved:');
        foreach ($paths as $p) {
            $this->line("     → {$p}");
        }
    }
}
