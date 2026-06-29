<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Analyzers\StaticOrchestrator;
use CodeGuardian\Laravel\PackageOrchestrator;
use CodeGuardian\Laravel\Support\AiClient;
use CodeGuardian\Laravel\Support\CodeScanner;
use CodeGuardian\Laravel\Support\FindingFilter;
use CodeGuardian\Laravel\Support\ReportFormatter;
use CodeGuardian\Laravel\Support\RiskScorer;
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
                            {--mode=     : Engine mode: static | hybrid (static + Claude AI) | ai (Claude only)}
                            {--refactor  : After analysis, start interactive refactoring workflow}
                            {--severity=     : Filter findings by severity (csv): critical,high,medium,low}
                            {--min-severity= : Keep only findings at or above this severity}
                            {--category=     : Filter findings by category substring (csv), e.g. sql_injection,n_plus_one}
                            {--confidence=   : Filter findings by confidence (csv): high,medium,low}
                            {--owasp=        : Filter findings by OWASP tag substring (csv), e.g. A03,A01}
                            {--cwe=          : Filter findings by CWE id substring (csv), e.g. CWE-89}
                            {--no-report : Print findings to console only, no files saved}';

    protected $description = 'Run a full CodeGuardian analysis — Principal SE + Senior DevOps + Senior QA level review';

    public function handle(
        CodeScanner     $scanner,
        ReportFormatter $formatter
    ): int {
        $this->printBanner();

        $path      = $this->option('path') ?: base_path();
        $moduleOpt = $this->option('module');
        $apiOpt    = $this->option('api');
        $type      = $this->option('type') ?: $this->detectProjectType($path);
        $format    = $this->option('format') ?: config('codeguardian.output.format', 'both');
        $noReport  = $this->option('no-report');

        // Auto-resolve engine mode: if Claude/AI key configured, default to hybrid
        $mode = $this->resolveMode();

        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $scopeLabel  = $moduleOpt ? "Module: {$moduleOpt}" : ($apiOpt ? "API: {$apiOpt}" : 'Full project');
        $engineLabel = $this->engineLabel($mode);

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

        $maxFiles = (int) config('codeguardian.analysis.max_files_per_scan', 2000);
        if ($totalFiles > $maxFiles) {
            $this->warn("  ⚠  Large codebase detected ({$totalFiles} files). Analysis may take a few minutes.");
            $this->warn("     Tip: use --path=app/Http/Controllers or --module=YourModule to narrow scope.");
            if ($this->input->isInteractive() && ! $this->confirm("  Continue scanning all {$totalFiles} files?", true)) {
                $this->info('  Cancelled. Re-run with --path= to narrow the scope.');
                return self::SUCCESS;
            }
        }
        $this->newLine();

        // Run analysis based on mode
        $results = match ($mode) {
            'ai'     => $this->runAiAnalysis($context),
            'hybrid' => $this->runHybridAnalysis($context, $path),
            default  => $this->runStaticAnalysis($context['files'] ?? [], $path),
        };

        if ($results === null) {
            return self::FAILURE;
        }

        // Optional, combinable finding filters (severity/category/confidence/owasp/cwe)
        $filterSpec = FindingFilter::fromOptions([
            'severity'     => $this->option('severity'),
            'min-severity' => $this->option('min-severity'),
            'category'     => $this->option('category'),
            'confidence'   => $this->option('confidence'),
            'owasp'        => $this->option('owasp'),
            'cwe'          => $this->option('cwe'),
        ]);
        if (! FindingFilter::isEmpty($filterSpec)) {
            $results = FindingFilter::applyToResult($results, $filterSpec);
            $kept    = $results['summary']['total_issues'] ?? 0;
            $this->newLine();
            $this->line('  🔎 Filters applied — ' . $kept . ' finding(s) match.');
        }

        // Explainable risk assessment (severity × confidence) — Phase 6
        $risk = RiskScorer::assess($results['all_findings'] ?? []);
        $results['summary']['risk_score']     = $risk['risk_score'];
        $results['summary']['risk_level']     = $risk['risk_level'];
        $results['summary']['risk_reasoning'] = $risk['reasoning'];

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
    // Mode resolution — auto-upgrade to hybrid when an AI key is present
    // ──────────────────────────────────────────────────────────────────────────

    private function resolveMode(): string
    {
        $explicit = $this->option('mode') ?: config('codeguardian.mode', 'static');

        if ($explicit !== 'static') {
            return $explicit; // user or config explicitly chose ai / hybrid
        }

        // Auto-upgrade to hybrid if an AI key is configured
        return AiClient::hasApiKey() ? 'hybrid' : 'static';
    }

    private function engineLabel(string $mode): string
    {
        return match ($mode) {
            'hybrid' => '🔀 Hybrid: Static engine + Claude AI (Principal SE · Senior DevOps · Senior QA)',
            'ai'     => '🤖 Claude AI only (Principal SE · Senior DevOps · Senior QA)',
            default  => '⚡ Static engine (no API key needed)',
        };
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Static analysis (fast, no API key)
    // ──────────────────────────────────────────────────────────────────────────

    private function runStaticAnalysis(array $files, string $scanPath): ?array
    {
        $orchestrator    = new StaticOrchestrator();
        $requestedAgents = $this->resolveRequestedAgents();

        $options = [
            'architecture' => empty($requestedAgents) || in_array('architect', $requestedAgents),
            'security'     => empty($requestedAgents) || in_array('security', $requestedAgents),
            'performance'  => empty($requestedAgents) || in_array('performance', $requestedAgents),
            'tech_debt'    => empty($requestedAgents) || in_array('tech_debt', $requestedAgents),
        ];

        foreach (array_keys(array_filter($options)) as $name) {
            $this->line("  Running {$name} analyzer...");
        }

        $result = $orchestrator->analyze($files, $options, $scanPath);

        return $this->normalizeStaticResult($result, $scanPath);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Hybrid analysis — static for all files, Claude AI for hotspot files
    // ──────────────────────────────────────────────────────────────────────────

    private function runHybridAnalysis(array $context, string $scanPath): ?array
    {
        // Step 1: Static analysis on full codebase
        $this->line('  [1/2] Running static analysis on all files...');
        $staticResult = $this->runStaticAnalysis($context['files'] ?? [], $scanPath);

        if ($staticResult === null) {
            return null;
        }

        // Step 2: Identify hotspot files (files with most issues)
        $hotspotPaths = array_keys($staticResult['summary']['hotspot_files'] ?? []);
        if (empty($hotspotPaths)) {
            // Fall back to top findings' files
            $hotspotPaths = array_unique(array_filter(array_map(
                fn($f) => $f['file'] ?? null,
                array_slice($staticResult['all_findings'] ?? [], 0, 20)
            )));
        }

        $maxHotspots = (int) config('codeguardian.analysis.ai_hotspot_limit', 15);
        $hotspotPaths = array_slice($hotspotPaths, 0, $maxHotspots);

        if (empty($hotspotPaths)) {
            $this->line('  [2/2] No hotspot files found — skipping AI deep analysis.');
            $staticResult['engine'] = 'static';
            return $staticResult;
        }

        $this->line("  [2/2] Running Claude AI deep analysis on " . count($hotspotPaths) . " hotspot file(s)...");

        // Build focused context for AI — only hotspot files
        $allFiles = $context['files'] ?? [];
        $hotspotFiles = [];
        foreach ($hotspotPaths as $hPath) {
            foreach ($allFiles as $filePath => $content) {
                if (str_contains($filePath, $hPath) || $filePath === $hPath) {
                    $hotspotFiles[$filePath] = $content;
                }
            }
        }

        if (empty($hotspotFiles)) {
            $hotspotFiles = array_slice($allFiles, 0, $maxHotspots, true);
        }

        $aiContext = array_merge($context, [
            'files'   => $hotspotFiles,
            'summary' => [
                'total_files' => count($hotspotFiles),
                'total_lines' => array_sum(array_map(fn($c) => substr_count($c, "\n") + 1, $hotspotFiles)),
            ],
        ]);

        $aiResults = $this->runAiAnalysis($aiContext);

        if ($aiResults === null) {
            $this->warn('  ⚠  AI analysis failed — using static results only.');
            $staticResult['engine'] = 'static';
            return $staticResult;
        }

        // Step 3: Merge static + AI findings
        return $this->mergeResults($staticResult, $aiResults, $scanPath);
    }

    private function mergeResults(array $staticResult, array $aiResult, string $scanPath): array
    {
        $aiFindings     = $aiResult['agent_results'] ?? [];
        $staticFindings = $staticResult['agent_results'] ?? [];

        // Merge agent results — AI enriches static
        $merged = $staticFindings;
        foreach ($aiFindings as $agent => $aiAgentResult) {
            if (! isset($merged[$agent])) {
                $merged[$agent] = $aiAgentResult;
                continue;
            }

            // Combine findings from both; AI findings go first (richer detail)
            $existingFindings = $merged[$agent]['findings'] ?? [];
            $newFindings      = $aiAgentResult['findings'] ?? [];
            $merged[$agent]['findings'] = array_merge($newFindings, $existingFindings);

            // Use AI scores when available (more nuanced)
            foreach (['architecture_score', 'security_score', 'performance_score', 'tech_debt_score', 'debt_score'] as $scoreKey) {
                if (isset($aiAgentResult[$scoreKey])) {
                    $merged[$agent][$scoreKey] = $aiAgentResult[$scoreKey];
                }
            }
        }

        // Rebuild all_findings from merged agents
        $allFindings = [];
        foreach ($merged as $agentData) {
            foreach ($agentData['findings'] ?? [] as $f) {
                $allFindings[] = $f;
            }
        }

        // Rebuild severity counts
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($allFindings as $f) {
            $sev = $f['severity'] ?? 'low';
            $counts[$sev] = ($counts[$sev] ?? 0) + 1;
        }

        // Rebuild scores
        $scores = [];
        foreach ($merged as $agentData) {
            foreach ($agentData as $k => $v) {
                if (str_ends_with($k, '_score') && is_numeric($v)) {
                    $scores[$k] = $v;
                }
            }
        }
        $overallScore = empty($scores) ? 0 : (int) round(array_sum($scores) / count($scores));

        // Hotspot files
        $fileIssueCounts = [];
        foreach ($allFindings as $f) {
            $file = $f['file'] ?? 'unknown';
            $fileIssueCounts[$file] = ($fileIssueCounts[$file] ?? 0) + 1;
        }
        arsort($fileIssueCounts);
        $hotspotFiles = array_slice($fileIssueCounts, 0, 10, true);

        // Top findings by severity
        usort($allFindings, function ($a, $b) {
            $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            return ($order[$a['severity'] ?? 'low'] ?? 3) <=> ($order[$b['severity'] ?? 'low'] ?? 3);
        });

        return [
            'files_scanned'  => $staticResult['files_scanned'],
            'total_lines'    => $staticResult['total_lines'],
            'overall_score'  => $overallScore,
            'grade'          => $this->scoreToGrade($overallScore),
            'scores'         => $scores,
            'agent_results'  => $merged,
            'all_findings'   => $allFindings,
            'project_name'   => basename($scanPath),
            'project_type'   => 'laravel',
            'scanned_at'     => now()->toISOString(),
            'engine'         => 'hybrid',
            'summary'        => [
                'total_files'   => $staticResult['files_scanned'] ?? 0,
                'total_lines'   => $staticResult['total_lines'] ?? 0,
                'total_issues'  => count($allFindings),
                'critical'      => $counts['critical'],
                'high'          => $counts['high'],
                'medium'        => $counts['medium'],
                'low'           => $counts['low'],
                'top_findings'  => array_slice($allFindings, 0, 10),
                'hotspot_files' => $hotspotFiles,
            ],
        ];
    }

    private function scoreToGrade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default      => 'F',
        };
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AI analysis — pure Claude (no API key? returns null gracefully)
    // ──────────────────────────────────────────────────────────────────────────

    private function runAiAnalysis(array $context): ?array
    {
        if (! AiClient::hasApiKey()) {
            $this->warn('  ⚠  No AI API key configured. Set CODEGUARDIAN_CLAUDE_KEY in .env');
            $this->warn('     Falling back to static engine...');
            return $this->runStaticAnalysis($context['files'] ?? [], $context['scan_path'] ?? base_path());
        }

        $provider = config('codeguardian.provider', 'claude');
        $this->line("  Using AI provider: {$provider}");

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
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function resolveRequestedAgents(): array
    {
        $agentsOpt = $this->option('agents');
        return $agentsOpt ? array_map('trim', explode(',', $agentsOpt)) : [];
    }

    private function normalizeStaticResult(array $raw, string $scanPath = ''): array
    {
        $agentResults = [];
        foreach ($raw['agents'] as $agentData) {
            $agentResults[$agentData['agent']] = $agentData;
        }

        $scores = [];
        foreach ($raw['agents'] as $agentData) {
            foreach (array_keys($agentData) as $k) {
                if (str_ends_with($k, '_score')) {
                    $scores[$k] = $agentData[$k];
                    break;
                }
            }
        }

        $bySeverity = $raw['summary']['by_severity'] ?? ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        return [
            'files_scanned'  => $raw['files_scanned'],
            'total_lines'    => $raw['total_lines'],
            'overall_score'  => $raw['overall_score'],
            'grade'          => $raw['grade'],
            'scores'         => $scores,
            'agent_results'  => $agentResults,
            'all_findings'   => $raw['all_findings'],
            'project_name'   => basename($scanPath ?: ($raw['scan_path'] ?? base_path())),
            'project_type'   => 'laravel',
            'scanned_at'     => now()->toISOString(),
            'engine'         => 'static',
            'summary'        => [
                'total_files'   => $raw['files_scanned']          ?? 0,
                'total_lines'   => $raw['total_lines']             ?? 0,
                'total_issues'  => $raw['summary']['total_issues'] ?? 0,
                'critical'      => $bySeverity['critical'] ?? 0,
                'high'          => $bySeverity['high']     ?? 0,
                'medium'        => $bySeverity['medium']   ?? 0,
                'low'           => $bySeverity['low']      ?? 0,
                'top_findings'  => $raw['summary']['top_findings']  ?? [],
                'hotspot_files' => $raw['summary']['hotspot_files'] ?? [],
            ],
        ];
    }

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
        $engine  = $results['engine'] ?? $mode;

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  ANALYSIS SUMMARY  [engine: ' . strtoupper($engine) . ']');
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

        // Explainable risk assessment
        if (isset($summary['risk_score'])) {
            $level = strtoupper((string) ($summary['risk_level'] ?? ''));
            $rs    = $summary['risk_score'];
            $color = $rs >= 45 ? 'error' : ($rs >= 20 ? 'warn' : 'info');
            $this->newLine();
            $this->{$color}("  Risk Score: {$rs}/100  ({$level})");
            foreach ((array) ($summary['risk_reasoning'] ?? []) as $reason) {
                $this->line("    • {$reason}");
            }
        }

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
