<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Analyzers\SecurityAnalyzer;
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

    protected $description = 'Run a security-only scan (SQL injection, XSS, hardcoded secrets, mass assignment, etc.) — no API key needed';

    public function handle(CodeScanner $scanner, ReportFormatter $formatter): int
    {
        $path   = $this->option('path') ?: base_path();
        $type   = $this->option('type') ?: (file_exists($path . '/pubspec.yaml') ? 'flutter' : 'laravel');
        $failOn = $this->option('fail-on') ?? 'critical';

        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $this->info('🔒 CodeGuardian — Security Scan  (static engine, no API key needed)');
        $this->info("   Scanning: {$path} [{$type}]");
        $this->newLine();

        $context = $scanner->buildContext($path, $type);
        $files   = $context['files'] ?? [];
        $this->line("   Files found: " . count($files));

        $analyzer = new SecurityAnalyzer();
        $result   = $analyzer->analyze($files);

        $findings = $result['findings'] ?? [];
        $score    = $result['security_score'] ?? 0;

        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  SECURITY SCAN RESULTS');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $color = $score >= 80 ? 'info' : ($score >= 60 ? 'warn' : 'error');
        $this->{$color}("  Security Score: {$score}/100");

        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($findings as $f) {
            $counts[$f['severity']] = ($counts[$f['severity']] ?? 0) + 1;
        }

        $this->newLine();
        $icons = ['critical' => '🔴', 'high' => '🟠', 'medium' => '🟡', 'low' => '🟢'];
        foreach ($counts as $level => $count) {
            if ($count > 0) {
                $this->line("  {$icons[$level]} " . strtoupper($level) . ": {$count}");
            }
        }

        if (! empty($findings)) {
            $this->newLine();
            $this->info('  FINDINGS:');
            $this->newLine();
            foreach ($findings as $f) {
                $sev  = strtoupper($f['severity']);
                $file = basename($f['file']);
                $line = $f['line_start'] ? ":{$f['line_start']}" : '';

                $this->line("  [{$sev}] {$f['title']}");
                $this->line("         → {$file}{$line}");
                $this->line("         {$f['description']}");

                if (! empty($f['recommendation'])) {
                    $this->line("         Fix: {$f['recommendation']}");
                }
                if (! empty($f['code_before']) && ! empty($f['code_after'])) {
                    $this->line("         Before: {$f['code_before']}");
                    $this->line("         After:  {$f['code_after']}");
                }
                $this->newLine();
            }
        } else {
            $this->info('  ✅  No security issues found!');
        }

        // Always save HTML + JSON reports (same as codeguardian:analyze)
        $outputDir  = $this->option('output')
            ?: storage_path(config('codeguardian.output.report_dir', 'codeguardian/reports'));

        $reportData = [
            'project_name'  => basename($path),
            'project_type'  => $type,
            'scanned_at'    => now()->toISOString(),
            'overall_score' => $score,
            'engine'        => 'static',
            'files_scanned' => count($files),
            'total_lines'   => array_sum(array_map(fn($c) => substr_count($c, "\n") + 1, $files)),
            'scores'        => ['security_score' => $score],
            'agent_results' => ['security' => $result],
            'summary'       => [
                'total_files'   => count($files),
                'total_lines'   => array_sum(array_map(fn($c) => substr_count($c, "\n") + 1, $files)),
                'total_issues'  => count($findings),
                'critical'      => $counts['critical'],
                'high'          => $counts['high'],
                'medium'        => $counts['medium'] ?? 0,
                'low'           => $counts['low'] ?? 0,
            ],
        ];

        $paths = $formatter->save($reportData, $outputDir, 'both');
        $this->newLine();
        $this->info('📄 Reports saved:');
        foreach ($paths as $p) {
            $this->line("   → {$p}");
        }

        $failCounts = match ($failOn) {
            'medium' => $counts['critical'] + $counts['high'] + $counts['medium'],
            'high'   => $counts['critical'] + $counts['high'],
            default  => $counts['critical'],
        };

        return $failCounts > 0 ? self::FAILURE : self::SUCCESS;
    }
}
