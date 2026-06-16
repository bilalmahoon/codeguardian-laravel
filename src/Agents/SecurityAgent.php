<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Agents;

class SecurityAgent extends BasePackageAgent
{
    public function getName(): string
    {
        return 'security';
    }

    protected function getSystemPrompt(): string
    {
        $base = $this->loadPrompt('security');
        if ($base) return $base;

        return <<<'PROMPT'
You are a Staff-level application security engineer (OWASP, SANS).
Find all security vulnerabilities: SQL injection, XSS, CSRF, missing authorization, broken authentication,
secret exposure, mass assignment, IDOR, insecure storage, hardcoded API keys.
Return ONLY valid JSON: {"agent":"security","security_score":0-100,"findings":[{"category":"...","severity":"critical|high|medium|low","risk_level":"...","title":"...","description":"...","file":"...","line_start":0,"line_end":0,"code_snippet":"...","suggested_fix":"...","code_before":"...","code_after":"..."}],"summary":{"total_issues":0,"critical":0,"high":0,"medium":0,"low":0}}
PROMPT;
    }

    protected function buildUserPrompt(array $context): string
    {
        $type  = $context['project_type'] ?? 'laravel';
        $name  = $context['project_name'] ?? 'Project';
        $files = $this->prepareFiles($context['files'] ?? []);

        return "Perform a security audit for {$type} project: {$name}\n" .
               $this->formatFilesForPrompt($files);
    }
}
