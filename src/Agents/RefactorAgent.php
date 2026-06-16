<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Agents;

/**
 * Refactors a single file based on the issues found for it.
 * Returns the complete refactored file content (ready to write to disk).
 */
class RefactorAgent extends BasePackageAgent
{
    public function getName(): string
    {
        return 'refactor';
    }

    protected function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a Staff-level Laravel/Flutter engineer performing a careful, targeted refactoring.

Rules:
- Make ONLY the changes required to fix the reported issues
- Do NOT change unrelated code, variable names, or formatting outside the changed area
- Preserve all existing functionality — this is a behavior-preserving refactoring
- Keep the same method signatures, return types, and public API
- Add PHPDoc where missing on refactored methods
- Do NOT add/remove use statements that are unrelated to the fix

Return a JSON object with EXACTLY this structure:
{
  "agent": "refactor",
  "file": "app/Http/Controllers/UserController.php",
  "original_file": "...(original content)...",
  "refactored_file": "...(complete refactored PHP file content)...",
  "changes": [
    {
      "type": "fix|extract|rename|simplify|add_validation|add_authorization|add_cache|remove_n+1",
      "description": "What was changed and why",
      "lines_before": "...",
      "lines_after": "..."
    }
  ],
  "tests_needed": [
    "Test scenario description for each change made"
  ]
}

CRITICAL: The "refactored_file" value must be the COMPLETE file content including all original code, not just the changed parts.
PROMPT;
    }

    protected function buildUserPrompt(array $context): string
    {
        $filePath    = $context['file_path'] ?? 'unknown';
        $fileContent = $context['file_content'] ?? '';
        $issues      = $context['issues'] ?? [];

        $issuesText = '';
        foreach ($issues as $i => $issue) {
            $num         = $i + 1;
            $sev         = $issue['severity'] ?? 'medium';
            $title       = $issue['title'] ?? 'Issue';
            $desc        = $issue['description'] ?? '';
            $rec         = $issue['recommendation'] ?? '';
            $lines       = isset($issue['line_start']) ? "Lines {$issue['line_start']}-{$issue['line_end']}" : '';
            $snippet     = $issue['code_snippet'] ?? '';
            $codeBefore  = $issue['code_before'] ?? '';
            $codeAfter   = $issue['code_after'] ?? '';

            $issuesText .= <<<TEXT

Issue #{$num} [{$sev}]: {$title}
{$lines}
Problem: {$desc}
Fix: {$rec}
CODE SNIPPET: {$snippet}
BEFORE: {$codeBefore}
AFTER (suggested): {$codeAfter}
TEXT;
        }

        return <<<PROMPT
Refactor the following file to fix ALL reported issues.

FILE: {$filePath}
---
{$fileContent}
---

ISSUES TO FIX:
{$issuesText}

Return the complete refactored file.
PROMPT;
    }

    /**
     * Refactor a single file and return the result.
     *
     * @param  string  $filePath     Relative path to the file (for display)
     * @param  string  $fileContent  Current content of the file
     * @param  array   $issues       Issues found for this specific file
     */
    public function refactorFile(string $filePath, string $fileContent, array $issues): array
    {
        return $this->analyze([
            'file_path'    => $filePath,
            'file_content' => $fileContent,
            'issues'       => $issues,
        ]);
    }
}
