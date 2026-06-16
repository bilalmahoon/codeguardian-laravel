<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Agents;

class ArchitectAgent extends BasePackageAgent
{
    public function getName(): string
    {
        return 'architect';
    }

    protected function getSystemPrompt(): string
    {
        $base = $this->loadPrompt('architect');
        if ($base) return $base;

        return <<<'PROMPT'
You are a Staff-level software architect specializing in Laravel and Flutter.
Analyze the provided codebase for SOLID violations, architecture anti-patterns, fat controllers/models,
missing service layers, poor dependency injection, and overall architecture quality.
Return ONLY valid JSON matching: {"agent":"architect","architecture_score":0-100,"findings":[{"category":"...","severity":"critical|high|medium|low","title":"...","description":"...","file":"...","line_start":0,"line_end":0,"code_snippet":"...","recommendation":"...","code_before":"...","code_after":"..."}],"summary":{"total_issues":0,"critical":0,"high":0,"medium":0,"low":0}}
PROMPT;
    }

    protected function buildUserPrompt(array $context): string
    {
        $type  = $context['project_type'] ?? 'laravel';
        $name  = $context['project_name'] ?? 'Project';
        $files = $this->prepareFiles($context['files'] ?? []);

        return "Perform a {$type} architecture review for project: {$name}\n" .
               "Total files: " . count($context['files'] ?? []) . "\n" .
               $this->formatFilesForPrompt($files);
    }
}
