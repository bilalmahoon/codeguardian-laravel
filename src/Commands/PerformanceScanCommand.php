<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Analyzers\PerformanceAnalyzer;
use CodeGuardian\Laravel\Support\CodeScanner;
use CodeGuardian\Laravel\Support\ReportFormatter;
use Illuminate\Console\Command;

class PerformanceScanCommand extends Command
{
    protected $signature = 'codeguardian:performance
                            {--path=   : Directory to scan (default: base_path())}
                            {--type=   : Project type: laravel or flutter}
                            {--output= : Save report to this directory}';

    protected $description = 'Scan for performance issues (N+1, missing indexes, select *, missing cache) — no API key needed';

    public function handle(CodeScanner $scanner, ReportFormatter $formatter): int
    {
        $path = $this->option('path') ?: base_path();
        $type = $this->option('type') ?: (file_exists($path . '/pubspec.yaml') ? 'flutter' : 'laravel');

        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $this->info('⚡ CodeGuardian — Performance Scan  (static engine, no API key needed)');
        $this->info("   Scanning: {$path} [{$type}]");
        $this->newLine();

        $context  = $scanner->buildContext($path, $type);
        $files    = $context['files'] ?? [];
        $this->line("   Files found: " . count($files));

        $analyzer = new PerformanceAnalyzer();
        $result   = $analyzer->analyze($files);

        $findings = $result['findings'] ?? [];
        $score    = $result['performance_score'] ?? 0;

        $this->newLine();
        $this->info('  PERFORMANCE SCAN RESULTS');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $color = $score >= 80 ? 'info' : ($score >= 60 ? 'warn' : 'error');
        $this->{$color}("  Performance Score: {$score}/100");

        $this->newLine();

        $grouped = [];
        foreach ($findings as $f) {
            $grouped[$f['category'] ?? 'other'][] = $f;
        }

        foreach ($grouped as $category => $items) {
            $label = ucwords(str_replace('_', ' ', $category));
            $this->info("  {$label} (" . count($items) . "):");
            foreach ($items as $f) {
                $sev  = strtoupper($f['severity']);
                $file = basename($f['file']);
                $line = $f['line_start'] ? ":{$f['line_start']}" : '';
                $this->line("    [{$sev}] {$f['title']}");
                $this->line("           → {$file}{$line}");
                if (! empty($f['recommendation'])) {
                    $this->line("           Fix: {$f['recommendation']}");
                }
                if (! empty($f['code_before'])) {
                    $this->line("           Before: {$f['code_before']}");
                    $this->line("           After:  {$f['code_after']}");
                }
            }
            $this->newLine();
        }

        if (empty($findings)) {
            $this->info('  ✅  No performance issues found!');
        }

        if ($outputDir = $this->option('output')) {
            $reportData = ['agent_results' => ['performance' => $result], 'summary' => $result['summary']];
            $paths      = $formatter->save($reportData, $outputDir, 'json');
            $this->info('📄 Report saved: ' . implode(', ', $paths));
        }

        return self::SUCCESS;
    }
}
