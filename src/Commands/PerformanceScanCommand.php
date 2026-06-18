<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Analyzers\PerformanceAnalyzer;
use CodeGuardian\Laravel\Agents\PerformanceAgent;
use CodeGuardian\Laravel\Support\AiClient;
use CodeGuardian\Laravel\Support\CodeScanner;
use CodeGuardian\Laravel\Support\ReportFormatter;
use Illuminate\Console\Command;

class PerformanceScanCommand extends Command
{
    protected $signature = 'codeguardian:performance
                            {--path=   : Directory to scan (default: base_path())}
                            {--type=   : Project type: laravel or flutter}
                            {--output= : Save report to this directory}
                            {--mode=   : Engine mode: static | hybrid | ai (auto-detected when API key present)}';

    protected $description = 'Senior Performance Engineer review — N+1, missing indexes, cache, memory leaks — with Claude AI when key is configured';

    public function handle(CodeScanner $scanner, ReportFormatter $formatter): int
    {
        $path = $this->option('path') ?: base_path();
        $type = $this->option('type') ?: (file_exists($path . '/pubspec.yaml') ? 'flutter' : 'laravel');
        $mode = $this->resolveMode();

        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $engineLabel = match ($mode) {
            'hybrid' => '🔀 Hybrid: Static patterns + Claude AI (Senior Performance Engineer level)',
            'ai'     => '🤖 Claude AI — Senior Performance Engineer Review',
            default  => '⚡ Static engine (no API key needed)',
        };

        $this->info("⚡ CodeGuardian — Performance Scan");
        $this->info("   Scanning: {$path} [{$type}]");
        $this->info("   Engine: {$engineLabel}");
        $this->newLine();

        $context = $scanner->buildContext($path, $type);
        $files   = $context['files'] ?? [];
        $this->line("   Files found: " . count($files));
        $this->newLine();

        [$findings, $score, $biggestWin] = match ($mode) {
            'hybrid' => $this->runHybridPerformance($files, $context),
            'ai'     => $this->runAiPerformance($context),
            default  => $this->runStaticPerformance($files),
        };

        // Print results
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  PERFORMANCE SCAN RESULTS  [engine: ' . strtoupper($mode) . ']');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $color = $score >= 80 ? 'info' : ($score >= 60 ? 'warn' : 'error');
        $this->{$color}("  Performance Score: {$score}/100");

        if ($biggestWin) {
            $this->newLine();
            $this->info("  Biggest Win: {$biggestWin}");
        }

        $this->newLine();

        $grouped = [];
        foreach ($findings as $f) {
            $grouped[$f['category'] ?? 'other'][] = $f;
        }

        foreach ($grouped as $category => $items) {
            $label = ucwords(str_replace('_', ' ', $category));
            $this->info("  {$label} (" . count($items) . "):");
            foreach ($items as $f) {
                $sev  = strtoupper($f['severity'] ?? 'low');
                $file = basename($f['file'] ?? 'unknown');
                $line = ($f['line_start'] ?? 0) ? ":{$f['line_start']}" : '';
                $this->line("    [{$sev}] {$f['title']}");
                $this->line("           → {$file}{$line}");

                // Show estimated impact when available (AI findings)
                if (! empty($f['estimated_impact'])) {
                    $this->warn("           Impact: {$f['estimated_impact']}");
                }
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

        // Save reports
        $totalLines = array_sum(array_map(fn($c) => substr_count($c, "\n") + 1, $files));
        $counts     = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($findings as $f) {
            $sev = $f['severity'] ?? 'low';
            $counts[$sev] = ($counts[$sev] ?? 0) + 1;
        }

        $outputDir  = $this->option('output')
            ?: storage_path(config('codeguardian.output.report_dir', 'codeguardian/reports'));

        $agentResult = ['agent' => 'performance', 'performance_score' => $score, 'findings' => $findings];
        if ($biggestWin) {
            $agentResult['summary']['biggest_win'] = $biggestWin;
        }

        $reportData = [
            'project_name'  => basename($path),
            'project_type'  => $type,
            'scanned_at'    => now()->toISOString(),
            'overall_score' => $score,
            'engine'        => $mode,
            'files_scanned' => count($files),
            'total_lines'   => $totalLines,
            'scores'        => ['performance_score' => $score],
            'agent_results' => ['performance' => $agentResult],
            'summary'       => [
                'total_files'   => count($files),
                'total_lines'   => $totalLines,
                'total_issues'  => count($findings),
                'critical'      => $counts['critical'],
                'high'          => $counts['high'],
                'medium'        => $counts['medium'],
                'low'           => $counts['low'],
            ],
        ];

        $paths = $formatter->save($reportData, $outputDir, 'both');
        $this->newLine();
        $this->info('📄 Reports saved:');
        foreach ($paths as $p) {
            $this->line("   → {$p}");
        }

        return self::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function resolveMode(): string
    {
        $explicit = $this->option('mode') ?: config('codeguardian.mode', 'static');
        if ($explicit !== 'static') return $explicit;
        return AiClient::hasApiKey() ? 'hybrid' : 'static';
    }

    /** @return array{0: array, 1: int, 2: string|null} [findings, score, biggestWin] */
    private function runStaticPerformance(array $files): array
    {
        $this->line('  Running static performance analyzer...');
        $analyzer = new PerformanceAnalyzer();
        $result   = $analyzer->analyze($files);

        return [$result['findings'] ?? [], $result['performance_score'] ?? 0, null];
    }

    /** @return array{0: array, 1: int, 2: string|null} */
    private function runAiPerformance(array $context): array
    {
        $this->line('  Running Claude AI performance review (Senior Performance Engineer level)...');
        $agent = new PerformanceAgent();

        try {
            $result    = $agent->analyze($context);
            $findings  = $result['findings'] ?? [];
            $score     = $result['performance_score'] ?? 0;
            $biggestWin = $result['summary']['biggest_win'] ?? null;

            return [$findings, $score, $biggestWin];
        } catch (\Throwable $e) {
            $this->warn("  ⚠  AI performance review failed: {$e->getMessage()}");
            $this->line('  Falling back to static analysis...');
            return $this->runStaticPerformance($context['files'] ?? []);
        }
    }

    /** @return array{0: array, 1: int, 2: string|null} */
    private function runHybridPerformance(array $files, array $context): array
    {
        $this->line('  [1/2] Running static performance patterns...');
        [$staticFindings, $staticScore, ] = $this->runStaticPerformance($files);

        $this->line('  [2/2] Running Claude AI deep performance review...');
        $hotspotFiles = $this->filterHotspotFiles($files, $staticFindings);
        $aiContext    = array_merge($context, ['files' => $hotspotFiles ?: $files]);

        try {
            $agent  = new PerformanceAgent();
            $result = $agent->analyze($aiContext);

            $aiFindings = $result['findings'] ?? [];
            $aiScore    = $result['performance_score'] ?? $staticScore;
            $biggestWin = $result['summary']['biggest_win'] ?? null;

            $merged = $this->deduplicateFindings(array_merge($aiFindings, $staticFindings));
            $score  = (int) round(($staticScore + $aiScore) / 2);

            return [$merged, $score, $biggestWin];
        } catch (\Throwable $e) {
            $this->warn("  ⚠  AI analysis failed: {$e->getMessage()} — using static only.");
            return [$staticFindings, $staticScore, null];
        }
    }

    private function filterHotspotFiles(array $files, array $findings): array
    {
        $hotspotPaths = array_unique(array_filter(array_map(
            fn($f) => $f['file'] ?? null,
            $findings
        )));

        $result = [];
        foreach ($hotspotPaths as $hPath) {
            foreach ($files as $filePath => $content) {
                if (str_ends_with($filePath, $hPath) || $filePath === $hPath) {
                    $result[$filePath] = $content;
                }
            }
        }

        return $result;
    }

    private function deduplicateFindings(array $findings): array
    {
        $seen   = [];
        $unique = [];
        foreach ($findings as $f) {
            $key = ($f['file'] ?? '') . ':' . ($f['line_start'] ?? 0) . ':' . ($f['title'] ?? '');
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[]   = $f;
            }
        }
        return $unique;
    }
}
