<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Analyzers\SecurityAnalyzer;
use CodeGuardian\Laravel\Agents\SecurityAgent;
use CodeGuardian\Laravel\Console\ConsoleReporter;
use CodeGuardian\Laravel\Support\AiClient;
use CodeGuardian\Laravel\Support\CodeScanner;
use CodeGuardian\Laravel\Support\FindingFilter;
use CodeGuardian\Laravel\Support\ReportFormatter;
use CodeGuardian\Laravel\Support\RiskScorer;
use Illuminate\Console\Command;

class SecurityScanCommand extends Command
{
    protected $signature = 'codeguardian:security
                            {--path=   : Directory to scan (default: base_path())}
                            {--type=   : Project type: laravel or flutter}
                            {--output= : Save report to this directory}
                            {--mode=   : Engine mode: static | hybrid | ai (auto-detected when API key present)}
                            {--severity=     : Filter findings by severity (csv): critical,high,medium,low}
                            {--min-severity= : Keep only findings at or above this severity}
                            {--category=     : Filter findings by category substring (csv)}
                            {--confidence=   : Filter findings by confidence (csv): high,medium,low}
                            {--owasp=        : Filter findings by OWASP tag substring (csv), e.g. A03,A01}
                            {--cwe=          : Filter findings by CWE id substring (csv), e.g. CWE-89}
                            {--plain         : Disable the live progress UI (plain log output, ideal for CI)}
                            {--fail-on=critical : Exit with error if issues found at this level: critical, high, medium}';

    protected $description = 'Senior DevOps security audit — SQL injection, XSS, IDOR, broken auth, secrets — with Claude AI when key is configured';

    public function handle(CodeScanner $scanner, ReportFormatter $formatter): int
    {
        $path   = $this->option('path') ?: base_path();
        $type   = $this->option('type') ?: (file_exists($path . '/pubspec.yaml') ? 'flutter' : 'laravel');
        $failOn = $this->option('fail-on') ?? 'critical';
        $mode   = $this->resolveMode();

        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $engineLabel = match ($mode) {
            'hybrid' => '🔀 Hybrid: Static patterns + Claude AI (Senior DevOps level)',
            'ai'     => '🤖 Claude AI — Senior DevOps Security Review',
            default  => '⚡ Static engine (no API key needed)',
        };

        $this->info("🔒 CodeGuardian — Security Scan");
        $this->info("   Scanning: {$path} [{$type}]");
        $this->info("   Engine: {$engineLabel}");
        $this->newLine();

        $context = $scanner->buildContext($path, $type);
        $files   = $context['files'] ?? [];
        $this->line("   Files found: " . count($files));
        $this->newLine();

        // Run analysis
        [$findings, $score, $aiSummary] = match ($mode) {
            'hybrid' => $this->runHybridSecurity($files, $context),
            'ai'     => $this->runAiSecurity($context),
            default  => $this->option('plain')
                ? $this->runStaticSecurity($files)
                : $this->runStaticSecurityLive($files),
        };

        // Optional, combinable finding filters
        $filterSpec = FindingFilter::fromOptions([
            'severity'     => $this->option('severity'),
            'min-severity' => $this->option('min-severity'),
            'category'     => $this->option('category'),
            'confidence'   => $this->option('confidence'),
            'owasp'        => $this->option('owasp'),
            'cwe'          => $this->option('cwe'),
        ]);
        if (! FindingFilter::isEmpty($filterSpec)) {
            $findings = FindingFilter::apply($findings, $filterSpec);
            $this->line('  🔎 Filters applied — ' . count($findings) . ' finding(s) match.');
            $this->newLine();
        }

        // Print results
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  SECURITY SCAN RESULTS  [engine: ' . strtoupper($mode) . ']');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $color = $score >= 80 ? 'info' : ($score >= 60 ? 'warn' : 'error');
        $this->{$color}("  Security Score: {$score}/100");

        if ($aiSummary) {
            $this->newLine();
            $this->warn("  Risk Assessment: {$aiSummary}");
        }

        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($findings as $f) {
            $sev = $f['severity'] ?? 'low';
            $counts[$sev] = ($counts[$sev] ?? 0) + 1;
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
                $sev  = strtoupper($f['severity'] ?? 'low');
                $file = basename($f['file'] ?? 'unknown');
                $line = ($f['line_start'] ?? 0) ? ":{$f['line_start']}" : '';

                $this->line("  [{$sev}] {$f['title']}");
                $this->line("         → {$file}{$line}");
                $this->line("         {$f['description']}");

                // Show exploit scenario when available (AI findings)
                if (! empty($f['exploit_scenario'])) {
                    $this->warn("         Exploit: {$f['exploit_scenario']}");
                }
                $taxonomy = array_filter([
                    ! empty($f['owasp']) ? "OWASP {$f['owasp']}" : ($f['owasp_reference'] ?? null),
                    ! empty($f['cwe']) ? $f['cwe'] : null,
                    ! empty($f['confidence']) ? "confidence: {$f['confidence']}" : null,
                ]);
                if (! empty($taxonomy)) {
                    $this->line('         ' . implode('  ·  ', $taxonomy));
                }
                if (! empty($f['impact'])) {
                    $this->line("         Impact: {$f['impact']}");
                }
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

        // Save reports
        $totalLines = array_sum(array_map(fn($c) => substr_count($c, "\n") + 1, $files));
        $outputDir  = $this->option('output')
            ?: storage_path(config('codeguardian.output.report_dir', 'codeguardian/reports'));

        $agentResult = ['agent' => 'security', 'security_score' => $score, 'findings' => $findings];
        if ($aiSummary) {
            $agentResult['summary']['risk_assessment'] = $aiSummary;
        }

        $reportData = [
            'project_name'  => basename($path),
            'project_type'  => $type,
            'scanned_at'    => now()->toISOString(),
            'overall_score' => $score,
            'engine'        => $mode,
            'files_scanned' => count($files),
            'total_lines'   => $totalLines,
            'scores'        => ['security_score' => $score],
            'agent_results' => ['security' => $agentResult],
            'summary'       => [
                'total_files'   => count($files),
                'total_lines'   => $totalLines,
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

    // ──────────────────────────────────────────────────────────────────────────

    private function resolveMode(): string
    {
        $explicit = $this->option('mode') ?: config('codeguardian.mode', 'static');
        if ($explicit !== 'static') return $explicit;
        return AiClient::hasApiKey() ? 'hybrid' : 'static';
    }

    /** @return array{0: array, 1: int, 2: string|null} [findings, score, aiSummary] */
    private function runStaticSecurity(array $files): array
    {
        $this->line('  Running static security analyzer...');
        $analyzer = new SecurityAnalyzer();
        $result   = $analyzer->analyze($files);

        return [$result['findings'] ?? [], $result['security_score'] ?? 0, null];
    }

    /** Static security scan with the premium live pipeline UI. */
    private function runStaticSecurityLive(array $files): array
    {
        $reporter = new ConsoleReporter(
            $this->output,
            [['key' => 'security', 'label' => 'Security Analysis']],
            'CodeGuardian · Security audit'
        );
        $reporter->setCount('files_total', count($files));
        $reporter->setCount('rules', 1);

        $reporter->start('security', count($files));
        $analyzer = new SecurityAnalyzer();
        $result   = $analyzer->analyze($files, fn(string $f) => $reporter->advance('security', $f));
        $findings = $result['findings'] ?? [];
        $reporter->finish('security', count($findings) . ' finding' . (count($findings) === 1 ? '' : 's'));

        $reporter->done();
        $reporter->executionStats();
        $this->newLine();

        return [$findings, $result['security_score'] ?? 0, null];
    }

    /** @return array{0: array, 1: int, 2: string|null} */
    private function runAiSecurity(array $context): array
    {
        $this->line('  Running Claude AI security review (Senior DevOps level)...');
        $agent = new SecurityAgent();

        try {
            $result   = $agent->analyze($context);
            $findings = $result['findings'] ?? [];
            $score    = $result['security_score'] ?? 0;
            $summary  = $result['summary']['risk_assessment'] ?? null;

            return [$findings, $score, $summary];
        } catch (\Throwable $e) {
            $this->warn("  ⚠  AI security review failed: {$e->getMessage()}");
            $this->line('  Falling back to static analysis...');
            return $this->runStaticSecurity($context['files'] ?? []);
        }
    }

    /** @return array{0: array, 1: int, 2: string|null} */
    private function runHybridSecurity(array $files, array $context): array
    {
        $this->line('  [1/2] Running static security patterns...');
        [$staticFindings, $staticScore, ] = $this->runStaticSecurity($files);

        $this->line('  [2/2] Running Claude AI deep security review...');
        // Only send files that have static findings (hotspots)
        $hotspotFiles = $this->filterHotspotFiles($files, $staticFindings);
        $aiContext    = array_merge($context, ['files' => $hotspotFiles ?: $files]);

        try {
            $agent  = new SecurityAgent();
            $result = $agent->analyze($aiContext);

            $aiFindings = $result['findings'] ?? [];
            $aiScore    = $result['security_score'] ?? $staticScore;
            $aiSummary  = $result['summary']['risk_assessment'] ?? null;

            // AI findings first (richer), then static (patterns AI may have missed)
            $merged = $this->deduplicateFindings(array_merge($aiFindings, $staticFindings));
            $score  = (int) round(($staticScore + $aiScore) / 2);

            return [$merged, $score, $aiSummary];
        } catch (\Throwable $e) {
            $this->warn("  ⚠  AI analysis failed: {$e->getMessage()} — using static only.");
            return [$staticFindings, $staticScore, null];
        }
    }

    /** Filter files that appear in findings (hotspots only). */
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

    /** Remove obvious duplicates (same file + same line + same title). */
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
