<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel;

use CodeGuardian\Laravel\Agents\ArchitectAgent;
use CodeGuardian\Laravel\Agents\PerformanceAgent;
use CodeGuardian\Laravel\Agents\QaAgent;
use CodeGuardian\Laravel\Agents\SecurityAgent;
use CodeGuardian\Laravel\Agents\TechDebtAgent;
use Closure;

class PackageOrchestrator
{
    /** Agents to run in a full analysis */
    private const AGENT_MAP = [
        'architect'   => ArchitectAgent::class,
        'security'    => SecurityAgent::class,
        'performance' => PerformanceAgent::class,
        'tech_debt'   => TechDebtAgent::class,
    ];

    /**
     * Run one or more agents over the given code context.
     *
     * @param  array          $context   Output of CodeScanner::buildContext()
     * @param  array|string   $agents    Which agents to run ('all' or array of names)
     * @param  Closure|null   $progress  Called after each agent: fn(string $agentName, bool $success)
     * @return array
     */
    public function run(array $context, array|string $agents = 'all', ?Closure $progress = null): array
    {
        $agentsToRun = $agents === 'all'
            ? array_keys(self::AGENT_MAP)
            : (array) $agents;

        $results = [];

        foreach ($agentsToRun as $name) {
            if (! isset(self::AGENT_MAP[$name])) {
                continue;
            }

            $agentClass = self::AGENT_MAP[$name];
            $agent      = new $agentClass();

            try {
                $result         = $agent->analyze($context);
                $results[$name] = $result;
                if ($progress) ($progress)($name, true, null);
            } catch (\Throwable $e) {
                $results[$name] = [
                    'agent'    => $name,
                    'error'    => $e->getMessage(),
                    'findings' => [],
                ];
                if ($progress) ($progress)($name, false, $e->getMessage());
            }
        }

        // QA agent runs after others to use their findings
        if ($agents === 'all' || in_array('qa', (array) $agents)) {
            $allIssues   = $this->collectIssues($results);
            $qaContext   = array_merge($context, ['issues' => $allIssues]);
            $qa          = new QaAgent();

            try {
                $results['qa'] = $qa->analyze($qaContext);
                if ($progress) ($progress)('qa', true, null);
            } catch (\Throwable $e) {
                $results['qa'] = ['agent' => 'qa', 'error' => $e->getMessage(), 'generated_tests' => []];
                if ($progress) ($progress)('qa', false, $e->getMessage());
            }
        }

        return $this->buildSummary($results, $context);
    }

    /**
     * Run only the security agent.
     */
    public function runSecurityScan(array $context, ?Closure $progress = null): array
    {
        return $this->run($context, ['security'], $progress);
    }

    /**
     * Run only the performance agent.
     */
    public function runPerformanceScan(array $context, ?Closure $progress = null): array
    {
        return $this->run($context, ['performance'], $progress);
    }

    /**
     * Run only the QA agent to generate tests.
     */
    public function generateTests(array $context, ?Closure $progress = null): array
    {
        return $this->run($context, ['qa'], $progress);
    }

    private function collectIssues(array $results): array
    {
        $issues = [];
        foreach ($results as $result) {
            foreach ($result['findings'] ?? [] as $finding) {
                $issues[] = $finding;
            }
        }
        return $issues;
    }

    private function buildSummary(array $results, array $context): array
    {
        $totalIssues   = 0;
        $criticalCount = 0;
        $highCount     = 0;
        $scores        = [];

        foreach ($results as $name => $result) {
            foreach ($result['findings'] ?? [] as $f) {
                $totalIssues++;
                match ($f['severity'] ?? '') {
                    'critical' => $criticalCount++,
                    'high'     => $highCount++,
                    default    => null,
                };
            }
            // Collect any named scores
            foreach (['architecture_score', 'security_score', 'performance_score', 'debt_score'] as $key) {
                if (isset($result[$key])) {
                    $scores[$key] = $result[$key];
                }
            }
        }

        $overallScore = empty($scores) ? null : (int) round(array_sum($scores) / count($scores));

        return [
            'project_type'  => $context['project_type'],
            'project_name'  => $context['project_name'],
            'scan_path'     => $context['scan_path'],
            'scanned_at'    => now()->toISOString(),
            'overall_score' => $overallScore,
            'scores'        => $scores,
            'summary'       => [
                'total_issues'   => $totalIssues,
                'critical'       => $criticalCount,
                'high'           => $highCount,
                'total_files'    => $context['summary']['total_files'] ?? 0,
                'total_lines'    => $context['summary']['total_lines'] ?? 0,
            ],
            'agent_results' => $results,
        ];
    }
}
