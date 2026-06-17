<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Analyzers\StaticOrchestrator;
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
                            {--agents=   : Comma-separated agents: architect,security,performance,tech_debt (default: all)}
                            {--output=   : Output directory for reports (default: storage/codeguardian/reports)}
                            {--format=   : Report format: json, html, or both (default: both)}
                            {--mode=     : Engine mode: static (default, no API key needed) | ai (requires API key)}
                            {--refactor  : After analysis, start interactive refactoring workflow}
                            {--no-report : Print findings to console only, no files saved}';

    protected $description = 'Run a full CodeGuardian analysis (architecture, security, performance, tech debt) — works without any API key';

    public function handle(
        CodeScanner     $scanner,
        ReportFormatter $formatter
    ): int {
        $this->printBanner();

        $path      = $this->option('path') ?: base_path();
        $moduleOpt = $this->option('module');
        $apiOpt    = $this->option('api');
        $type      = $this->option('type') ?: $this->detectProjectType($path);
        $mode      = $this->option('mode') ?: config('codeguardian.mode', 'static');
        $format    = $this->option('format') ?: config('codeguardian.output.format', 'both');
        $noReport  = $this->option('no-report');

        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $scopeLabel = $moduleOpt ? "Module: {$moduleOpt}" : ($apiOpt ? "API: {$apiOpt}" : 'Full project');
        $engineLabel = $mode === 'ai' ? '🤖 AI-powered (requires API key)' : '⚡ Static engine (no API key needed)';

        $this->info("📁 Scanning: {$path}");
        $this->info("🔧 Project type: {$type}  |  Scope: {$scopeLabel}");
        $this->info("🔍 Engine: {$engineLabel}");
        $this->newLine();

        // Scan files
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

        $totalFiles = $context['summary']['total_files'];
        $totalLines = $context['summary']['total_lines'];
        $this->info("  ✔  Found {$totalFiles} files ({$totalLines} lines)");
        $this->newLine();

        // Run analysis
        if ($mode === 'ai') {
            $results = $this->runAiAnalysis($context);
        } else {
            $results = $this->runStaticAnalysis($context['files'] ?? []);
        }

        if ($results === null) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->printSummary($results, $mode);

        // Save reports
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
            $args = ['--path' => $path, '--type' => $type, '--mode' => 'interactive'];
            if ($moduleOpt) $args['--module'] = $moduleOpt;
            if ($apiOpt)    $args['--api']    = $apiOpt;
            $this->call('codeguardian:refactor', $args);
        }

        $critical = $results['summary']['by_severity']['critical']
            ?? $results['summary']['critical']
            ?? 0;

        return $critical > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Static analysis (default — no API key)
    // ──────────────────────────────────────────────────────────────────────────

    private function runStaticAnalysis(array $files): ?array
    {
        $orchestrator = new StaticOrchestrator();

        $agentsOpt      = $this->option('agents');
        $requestedAgents = $agentsOpt ? array_map('trim', explode(',', $agentsOpt)) : [];

        $options = [
            'architecture' => empty($requestedAgents) || in_array('architect', $requestedAgents),
            'security'     => empty($requestedAgents) || in_array('security', $requestedAgents),
            'performance'  => empty($requestedAgents) || in_array('performance', $requestedAgents),
            'tech_debt'    => empty($requestedAgents) || in_array('tech_debt', $requestedAgents),
        ];

        $agentNames = array_keys(array_filter($options));
        foreach ($agentNames as $name) {
            $this->line("  Running {$name} analyzer...");
        }

        $result = $orchestrator->analyze($files, $options);

        // Normalize to format expected by formatter
        return $this->normalizeStaticResult($result);
    }

    private function normalizeStaticResult(array $raw): array
    {
        // Build agent_results from individual agent data
        $agentResults = [];
        foreach ($raw['agents'] as $agentData) {
            $agentResults[$agentData['agent']] = $agentData;
        }

        // Build scores map
        $scores = [];
        foreach ($raw['agents'] as $agentData) {
            $scoreKey = array_keys(array_filter(
                array_keys($agentData),
                fn($k) => str_ends_with($k, '_score')
            ));
            if (! empty($scoreKey)) {
                $sk            = $scoreKey[0];
                $scores[$sk]   = $agentData[$sk];
            }
        }

        return [
            'files_scanned'  => $raw['files_scanned'],
            'total_lines'    => $raw['total_lines'],
            'overall_score'  => $raw['overall_score'],
            'grade'          => $raw['grade'],
            'scores'         => $scores,
            'agent_results'  => $agentResults,
            'all_findings'   => $raw['all_findings'],
            'summary'        => [
                'total_issues' => $raw['summary']['total_issues'],
                'critical'     => $raw['summary']['by_severity']['critical'],
                'high'         => $raw['summary']['by_severity']['high'],
                'medium'       => $raw['summary']['by_severity']['medium'],
                'low'          => $raw['summary']['by_severity']['low'],
                'top_findings' => $raw['summary']['top_findings'],
                'hotspot_files' => $raw['summary']['hotspot_files'],
            ],
            'engine'         => 'static',
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AI analysis (optional — requires API key in .env)
    // ──────────────────────────────────────────────────────────────────────────

    private function runAiAnalysis(array $context): ?array
    {
        $provider = config('codeguardian.provider', 'openai');
        $this->warn("  Using AI provider: {$provider}");

        $orchestrator = app(PackageOrchestrator::class);
        $agentsOpt    = $this->option('agents');
        $agents       = $agentsOpt ? explode(',', $agentsOpt) : 'all';

        $results = $orchestrator->run($context, $agents, function (string $agent, bool $ok, ?string $err) {
            if ($ok) {
                $this->info("  ✔  {$agent} agent completed");
            } else {
                $this->warn("  ✗  {$agent} agent failed: {$err}");
            }
        });

        return $results;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Output helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function printBanner(): void
    {
        $this->info('');
        $this->info('  ██████╗ ██████╗ ██████╗ ███████╗ ██████╗ ██╗   ██╗ █████╗ ██████╗ ██████╗  ██╗ █████╗ ███╗   ██╗');
        $this->info('  CodeGuardian AI — Analyze. Improve. Validate. Report.');
        $this->info('');
    }

    private function printSummary(array $results, string $mode): void
    {
        $summary = $results['summary'] ?? [];
        $scores  = $results['scores'] ?? [];

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  ANALYSIS SUMMARY');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (isset($results['overall_score'])) {
            $score = $results['overall_score'];
            $grade = $results['grade'] ?? '';
            $color = $score >= 80 ? 'info' : ($score >= 60 ? 'warn' : 'error');
            $this->{$color}("  Overall Score: {$score}/100  (Grade: {$grade})");
        }

        foreach ($scores as $key => $value) {
            $label = ucwords(str_replace(['_score', '_'], [' ', ' '], $key));
            $color = $value >= 80 ? 'info' : ($value >= 60 ? 'warn' : 'error');
            $this->{$color}("  {$label}: {$value}/100");
        }

        $this->newLine();
        $total    = $summary['total_issues'] ?? 0;
        $critical = $summary['critical'] ?? 0;
        $high     = $summary['high'] ?? 0;
        $medium   = $summary['medium'] ?? 0;
        $low      = $summary['low'] ?? 0;

        if ($total === 0) {
            $this->info('  ✅ No issues found!');
        } else {
            $this->line("  Total Issues: {$total}");
            if ($critical > 0) $this->error("  🔴 Critical: {$critical}");
            if ($high > 0)     $this->warn("  🟠 High:     {$high}");
            if ($medium > 0)   $this->line("  🟡 Medium:   {$medium}");
            if ($low > 0)      $this->line("  🟢 Low:      {$low}");
        }

        // Top 5 findings
        $topFindings = $summary['top_findings'] ?? [];
        if (! empty($topFindings)) {
            $this->newLine();
            $this->info('  TOP FINDINGS:');
            foreach (array_slice($topFindings, 0, 5) as $f) {
                $sev   = strtoupper($f['severity']);
                $file  = $f['file'] ?? '';
                $title = mb_substr($f['title'], 0, 80);
                $this->line("  [{$sev}] {$title}");
                if ($file) $this->line("         → {$file}");
            }
        }

        // Hotspot files
        $hotspots = $summary['hotspot_files'] ?? [];
        if (! empty($hotspots)) {
            $this->newLine();
            $this->info('  MOST ISSUES IN:');
            foreach ($hotspots as $file => $count) {
                $this->line("  {$count} issues  → {$file}");
            }
        }

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    private function detectProjectType(string $path): string
    {
        if (file_exists($path . '/pubspec.yaml')) return 'flutter';
        return 'laravel';
    }
}
