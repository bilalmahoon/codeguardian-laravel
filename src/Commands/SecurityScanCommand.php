<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\PackageOrchestrator;
use CodeGuardian\Laravel\Support\CodeScanner;
use CodeGuardian\Laravel\Support\ReportFormatter;
use Illuminate\Console\Command;

class SecurityScanCommand extends Command
{
    protected $signature = 'codeguardian:security
                            {--path=   : Directory to scan (default: base_path())}
                            {--type=   : Project type: laravel or flutter}
                            {--output= : Save report to this directory}
                            {--fail-on=critical : Exit with error if issues found at this level: critical, high, medium}';

    protected $description = 'Run a security-only scan (OWASP, SQL injection, XSS, secrets, etc.)';

    public function handle(
        CodeScanner         $scanner,
        PackageOrchestrator $orchestrator,
        ReportFormatter     $formatter
    ): int {
        $path     = $this->option('path') ?: base_path();
        $type     = $this->option('type') ?: $this->detectType($path);
        $failOn   = $this->option('fail-on') ?? 'critical';

        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $this->info('🔒 CodeGuardian AI — Security Scan');
        $this->info("   Scanning: {$path} [{$type}]");
        $this->newLine();

        $context  = $scanner->buildContext($path, $type);
        $this->line("   Files: {$context['summary']['total_files']}");

        $results = $orchestrator->runSecurityScan($context, function (string $agent, bool $ok) {
            if ($ok) {
                $this->info("   ✔  Security analysis complete");
            }
        });

        $securityResult = $results['agent_results']['security'] ?? [];
        $findings       = $securityResult['findings'] ?? [];
        $score          = $securityResult['security_score'] ?? null;

        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  SECURITY SCAN RESULTS');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if ($score !== null) {
            $color = $score >= 80 ? 'info' : ($score >= 60 ? 'warn' : 'error');
            $this->{$color}("  Security Score: {$score}/100");
        }

        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($findings as $f) {
            $sev = $f['severity'] ?? 'low';
            $counts[$sev] = ($counts[$sev] ?? 0) + 1;
        }

        $this->newLine();
        foreach ($counts as $level => $count) {
            if ($count > 0) {
                $icons = ['critical' => '🔴', 'high' => '🟠', 'medium' => '🟡', 'low' => '🟢'];
                $icon  = $icons[$level] ?? '⚪';
                $this->line("  {$icon} " . strtoupper($level) . ": {$count}");
            }
        }

        if (! empty($findings)) {
            $this->newLine();
            $this->info('  FINDINGS:');
            $this->newLine();
            foreach ($findings as $f) {
                $sev  = strtoupper($f['severity'] ?? 'INFO');
                $file = $f['file'] ?? 'unknown';
                $line = isset($f['line_start']) ? ":{$f['line_start']}" : '';
                $this->line("  [{$sev}] {$f['title']}");
                $this->line("         {$file}{$line}");
                $this->line("         " . ($f['description'] ?? ''));
                if (! empty($f['suggested_fix'])) {
                    $this->line("         Fix: " . $f['suggested_fix']);
                }
                $this->newLine();
            }
        } else {
            $this->info('  ✅  No security issues found!');
        }

        // Save report
        if ($outputDir = $this->option('output')) {
            $paths = $formatter->save($results, $outputDir, 'json');
            $this->info("📄 Report saved: " . implode(', ', $paths));
        }

        // Exit code
        $failCounts = match ($failOn) {
            'medium'   => $counts['critical'] + $counts['high'] + $counts['medium'],
            'high'     => $counts['critical'] + $counts['high'],
            default    => $counts['critical'],
        };

        return $failCounts > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function detectType(string $path): string
    {
        return file_exists($path . '/pubspec.yaml') ? 'flutter' : 'laravel';
    }
}
