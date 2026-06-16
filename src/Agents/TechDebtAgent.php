<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Agents;

class TechDebtAgent extends BasePackageAgent
{
    public function getName(): string
    {
        return 'tech_debt';
    }

    protected function getSystemPrompt(): string
    {
        $base = $this->loadPrompt('tech_debt');
        if ($base) return $base;

        return <<<'PROMPT'
You are a Staff-level engineer specializing in code quality and technical debt reduction.
Find: dead code, duplicate logic, large classes (>300 LOC), complex methods (cyclomatic complexity > 10),
poor naming, missing documentation, TODO/FIXME comments, and outdated dependencies.
Return ONLY valid JSON: {"agent":"tech_debt","debt_score":0-100,"findings":[{"category":"dead_code|duplicate_logic|large_class|complex_method|poor_naming|missing_docs|todo|other","severity":"high|medium|low","title":"...","description":"...","file":"...","line_start":0,"line_end":0,"recommendation":"...","estimated_effort_hours":0}],"summary":{"total_issues":0,"estimated_total_effort_hours":0}}
PROMPT;
    }

    protected function buildUserPrompt(array $context): string
    {
        $type  = $context['project_type'] ?? 'laravel';
        $name  = $context['project_name'] ?? 'Project';
        $files = $this->prepareFiles($context['files'] ?? []);

        return "Find technical debt in {$type} project: {$name}\n" .
               $this->formatFilesForPrompt($files);
    }
}
