<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Agents;

class QaAgent extends BasePackageAgent
{
    public function getName(): string
    {
        return 'qa';
    }

    protected function getSystemPrompt(): string
    {
        $base = $this->loadPrompt('qa');
        if ($base) return $base;

        return <<<'PROMPT'
You are a Staff-level QA engineer who writes production-quality tests for Laravel and Flutter.
Generate comprehensive test cases covering: unit tests, feature tests, API tests, authorization tests,
widget tests, and integration tests. Cover happy paths, negative cases, edge cases, and boundary conditions.
Every test must be syntactically valid and runnable.
Return ONLY valid JSON: {"agent":"qa","findings":[],"generated_tests":[{"type":"unit|feature|api|widget|integration","framework":"phpunit|pest|flutter_test","class_name":"...","file_path":"...","test_code":"...","scenario":"...","preconditions":[],"steps":[],"expected_result":"...","coverage_area":"..."}]}
PROMPT;
    }

    protected function buildUserPrompt(array $context): string
    {
        $type   = $context['project_type'] ?? 'laravel';
        $name   = $context['project_name'] ?? 'Project';
        $issues = $context['issues'] ?? [];
        $files  = $this->prepareFiles($context['files'] ?? [], 60_000);

        $issueContext = '';
        if (! empty($issues)) {
            $issueContext = "\n\nKnown issues to write tests for:\n" . json_encode($issues, JSON_PRETTY_PRINT);
        }

        return "Generate tests for {$type} project: {$name}{$issueContext}\n" .
               $this->formatFilesForPrompt($files);
    }
}
