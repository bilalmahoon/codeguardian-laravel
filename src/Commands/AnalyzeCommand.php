<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\PackageOrchestrator;
use CodeGuardian\Laravel\Support\CodeScanner;
use CodeGuardian\Laravel\Support\ReportFormatter;
use Illuminate\Console\Command;

class AnalyzeCommand extends Command
{
    protected $signature = 'codeguardian:analyze
                            {--path=     : Directory to analyze (default: base_path())}
                            {--module=   : Analyze a specific module only (e.g. User, Order)}
                            {--api=      : Analyze APIs matching this filter (e.g. GET:/api/users, users)}
                            {--type=     : Project type: laravel or flutter (auto-detected if omitted)}
                            {--agents=   : Comma-separated agents to run: architect,security,performance,tech_debt,qa (default: all)}
                            {--output=   : Output directory for reports (default: storage/codeguardian/reports)}
                            {--format=   : Report format: json, html, or both (default: both)}
                            {--refactor  : After analysis ask to start interactive refactoring workflow}
                            {--no-report : Print findings to console only, no files saved}';

    protected $description = 'Run a full CodeGuardian AI analysis on your project (architecture, security, performance, tests)';

    public function handle(
        CodeScanner         $scanner,
        PackageOrchestrator $orchestrator,
        ReportFormatter     $formatter
    ): int {
        $this->info('');
        $this->info('  ██████╗ ██████╗ ██████╗ ███████╗ ██████╗ ██╗   ██╗ █████╗ ██████╗ ██████╗  ██╗ █████╗ ███╗   ██╗');
        $this->info('  CodeGuardian AI — Analyze. Improve. Validate. Report.');
        $this->info('');

        $path        = $this->option('path') ?: base_path();
        $moduleOpt   = $this->option('module');
        $apiOpt      = $this->option('api');
        $type        = $this->option('type') ?: $this->detectProjectType($path);
        $agentsOpt   = $this->option('agents');
        $agents      = $agentsOpt ? explode(',', $agentsOpt) : 'all';
        $format      = $this->option('format') ?: config('codeguardian.output.format', 'both');
        $noReport    = $this->option('no-report');

        // Validate path
        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $scopeLabel = $moduleOpt ? "Module: {$moduleOpt}" : ($apiOpt ? "API: {$apiOpt}" : 'Full project');
        $this->info("📁 Scanning: {$path}");
        $this->info("🔧 Project type: {$type}  |  Scope: {$scopeLabel}");
        $this->info("🤖 AI provider: " . config('codeguardian.provider', 'openai'));
        $this->newLine();

        // Scan files based on scope
        $this->line('  Scanning files...');
        try {
            $context = match (true) {
                $moduleOpt !== null => $scanner->buildContextForModule($path, $moduleOpt),
                $apiOpt    !== null => $scanner->buildContextForApi($path, $apiOpt),
                default             => $scanner->buildContext($path, $type),
            };
        } catch (\InvalidArgumentException $e) {
            $this->error("  " . $e->getMessage());
            return self::FAILURE;
        }
        $this->info("  ✔  Found {$context['summary']['total_files']} files ({$context['summary']['total_lines']} lines)");
        $this->newLine();

        // Run agents with progress
        $results = $orchestrator->run($context, $agents, function (string $agent, bool $ok, ?string $err) {
            if ($ok) {
                $this->info("  ✔  {$agent} agent completed");
            } else {
                $this->warn("  ✗  {$agent} agent failed: {$err}");
            }
        });

        $this->newLine();

        // Print summary
        $this->printSummary($results);

        // Save report
        if (! $noReport) {
            $outputDir = $this->option('output')
                ?: storage_path(config('codeguardian.output.report_dir', 'codeguardian/reports'));

            $paths = $formatter->save($results, $outputDir, $format);

            $this->newLine();
            $this->info('📄 Reports saved:');
            foreach ($paths as $p) {
                $this->line("   → {$p}");
            }
        }

        $this->newLine();

        // Optional: launch interactive refactoring
        if ($this->option('refactor') && ($results['summary']['total_issues'] ?? 0) > 0) {
            $this->newLine();
            $args = ['--path' => $path, '--type' => $type, '--mode' => 'interactive'];
            if ($moduleOpt) $args['--module'] = $moduleOpt;
            if ($apiOpt)    $args['--api']    = $apiOpt;

            $this->call('codeguardian:refactor', $args);
        }

        $critical = $results['summary']['critical'] ?? 0;
        return $critical > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function detectProjectType(string $path): string
    {
        if (file_exists($path . '/pubspec.yaml')) {
            return 'flutter';
        }
        if (file_exists($path . '/composer.json') || file_exists($path . '/artisan')) {
            return 'laravel';
        }
        return 'laravel';
    }

    private function printSummary(array $results): void
    {
        $summary = $results['summary'] ?? [];
        $scores  = $results['scores'] ?? [];

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  ANALYSIS SUMMARY');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (isset($results['overall_score'])) {
            $score = $results['overall_score'];
            $color = $score >= 80 ? 'info' : ($score >= 60 ? 'warn' : 'error');
            $this->{$color}("  Overall Score: {$score}/100");
        }

        foreach ($scores as $key => $value) {
            $label = ucwords(str_replace('_', ' ', $key));
            $color = $value >= 80 ? 'info' : ($value >= 60 ? 'warn' : 'error');
            $this->{$color}("  {$label}: {$value}/100");
        }

        $this->newLine();
        $total    = $summary['total_issues'] ?? 0;
        $critical = $summary['critical'] ?? 0;
        $high     = $summary['high'] ?? 0;

        $this->line("  Total Issues: {$total}");

        if ($critical > 0) {
            $this->error("  🔴 Critical: {$critical}");
        }
        if ($high > 0) {
            $this->warn("  🟠 High: {$high}");
        }

        // Print top critical findings
        $this->newLine();
        $this->info('  TOP FINDINGS:');
        $printed = 0;
        foreach ($results['agent_results'] ?? [] as $agentResult) {
            foreach ($agentResult['findings'] ?? [] as $finding) {
                if (in_array($finding['severity'] ?? '', ['critical', 'high']) && $printed < 5) {
                    $sev  = strtoupper($finding['severity'] ?? 'UNKNOWN');
                    $file = $finding['file'] ?? 'unknown';
                    $this->line("  [{$sev}] {$finding['title']} → {$file}");
                    $printed++;
                }
            }
        }

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}
