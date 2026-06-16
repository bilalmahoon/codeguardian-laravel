<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Agents;

class PerformanceAgent extends BasePackageAgent
{
    public function getName(): string
    {
        return 'performance';
    }

    protected function getSystemPrompt(): string
    {
        $base = $this->loadPrompt('performance');
        if ($base) return $base;

        return <<<'PROMPT'
You are a Staff-level performance engineer specializing in Laravel and Flutter.
Find N+1 queries, missing eager loading, missing DB indexes, cache opportunities,
unbuffered queries, heavy synchronous operations, Flutter widget rebuild issues, and memory leaks.
Return ONLY valid JSON: {"agent":"performance","performance_score":0-100,"findings":[{"category":"n_plus_one|missing_index|cache_opportunity|rebuild_issue|memory|other","severity":"critical|high|medium|low","title":"...","description":"...","file":"...","line_start":0,"line_end":0,"code_snippet":"...","recommendation":"...","code_before":"...","code_after":"...","estimated_improvement":"..."}],"summary":{"total_issues":0}}
PROMPT;
    }

    protected function buildUserPrompt(array $context): string
    {
        $type  = $context['project_type'] ?? 'laravel';
        $name  = $context['project_name'] ?? 'Project';
        $files = $this->prepareFiles($context['files'] ?? []);

        return "Find performance issues in {$type} project: {$name}\n" .
               $this->formatFilesForPrompt($files);
    }
}
