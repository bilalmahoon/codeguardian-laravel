<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\PackageOrchestrator;
use CodeGuardian\Laravel\Support\CodeScanner;
use CodeGuardian\Laravel\Support\ReportFormatter;
use Illuminate\Console\Command;

class PerformanceScanCommand extends Command
{
    protected $signature = 'codeguardian:performance
                            {--path=   : Directory to scan (default: base_path())}
                            {--type=   : Project type: laravel or flutter}
                            {--output= : Save report to this directory}';

    protected $description = 'Scan for performance issues (N+1 queries, missing indexes, cache opportunities, rebuild issues)';

    public function handle(
        CodeScanner         $scanner,
        PackageOrchestrator $orchestrator,
        ReportFormatter     $formatter
    ): int {
        $path = $this->option('path') ?: base_path();
        $type = $this->option('type') ?: (file_exists($path . '/pubspec.yaml') ? 'flutter' : 'laravel');

        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $this->info('⚡ CodeGuardian AI — Performance Scan');
        $this->info("   Scanning: {$path} [{$type}]");
        $this->newLine();

        $context = $scanner->buildContext($path, $type);
        $results = $orchestrator->runPerformanceScan($context, function (string $agent, bool $ok) {
            $this->info($ok ? '   ✔  Performance analysis complete' : '   ✗  Performance agent failed');
        });

        $perfResult = $results['agent_results']['performance'] ?? [];
        $findings   = $perfResult['findings'] ?? [];
        $score      = $perfResult['performance_score'] ?? null;

        $this->newLine();
        $this->info('  PERFORMANCE SCAN RESULTS');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if ($score !== null) {
            $color = $score >= 80 ? 'info' : ($score >= 60 ? 'warn' : 'error');
            $this->{$color}("  Performance Score: {$score}/100");
        }

        $this->newLine();

        // Group findings by category
        $grouped = [];
        foreach ($findings as $f) {
            $cat = $f['category'] ?? 'other';
            $grouped[$cat][] = $f;
        }

        foreach ($grouped as $category => $items) {
            $label = ucwords(str_replace('_', ' ', $category));
            $this->info("  {$label} (" . count($items) . "):");
            foreach ($items as $f) {
                $sev  = strtoupper($f['severity'] ?? 'low');
                $file = $f['file'] ?? 'unknown';
                $this->line("    [{$sev}] {$f['title']} → {$file}");
                if (! empty($f['estimated_improvement'])) {
                    $this->line("           Improvement: {$f['estimated_improvement']}");
                }
            }
            $this->newLine();
        }

        if (empty($findings)) {
            $this->info('  ✅  No performance issues found!');
        }

        if ($outputDir = $this->option('output')) {
            $paths = $formatter->save($results, $outputDir, 'json');
            $this->info("📄 Report saved: " . implode(', ', $paths));
        }

        return self::SUCCESS;
    }
}
