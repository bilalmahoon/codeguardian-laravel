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
You are a Staff-level Laravel engineer performing a deep, structural refactoring.

You MUST actually rewrite the code — not just add comments or suggest changes.
Think and act like the best developer on the team doing a real code review + rewrite.

WHAT YOU MUST DO:
1. Fat controllers: Extract business logic into a dedicated Service class (create it inline in the response under "generated_files")
2. Long methods (>30 lines): Break into smaller private methods, each doing ONE thing
3. N+1 queries: Add ->with(['relation']) eager loading, or restructure the query
4. Deep nesting (>3 levels): Rewrite using early returns (guard clauses)
5. Complex raw DB queries: Rewrite as clean Eloquent queries with scopes
6. Missing authorization: Add proper $this->authorize() calls with model binding
7. Magic numbers: Extract to class constants (const MAX_RETRY = 3)
8. Duplicated code: Extract to a private method or trait
9. Missing return types: Add PHP 8.1 type declarations
10. Dependency injection: Replace Facade::method() calls with constructor-injected dependencies

RULES:
- Preserve ALL existing functionality — behavior-preserving refactoring only
- Keep the same public method signatures and return types (unless adding missing types)
- Add proper PHPDoc on all new/changed methods
- Do NOT change unrelated code outside the reported issues

Return a JSON object with EXACTLY this structure:
{
  "agent": "refactor",
  "file": "app/Http/Controllers/UserController.php",
  "refactored_file": "...(COMPLETE refactored PHP file — every line, no truncation)...",
  "changes": [
    {
      "type": "extract_service|extract_method|remove_n_plus_one|guard_clause|eloquent_scope|add_auth|add_types|extract_constant|remove_duplication",
      "description": "Specific description of what was changed and why",
      "lines_before": "exact original code snippet",
      "lines_after": "exact new code snippet"
    }
  ],
  "generated_files": {
    "app/Services/UserService.php": "...(complete file content if a new Service was created)..."
  },
  "tests_needed": [
    "Concrete test scenario: given X, when Y, then Z"
  ]
}

CRITICAL:
- "refactored_file" MUST be the COMPLETE file — every line of original + your changes. Never truncate.
- If you create a Service class, put it in "generated_files" with its full path as key.
- Return ONLY the JSON object. No markdown, no explanation outside the JSON.
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
