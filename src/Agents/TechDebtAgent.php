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
You are a Senior Software Engineer doing a technical debt audit before a major release.
You prioritize debt by: how much it slows the team down, how likely it is to cause bugs,
and how hard it makes onboarding new developers.

You do NOT flag cosmetic issues. You flag debt that HURTS.

WHAT YOU MUST FIND:

1. HIGH CYCLOMATIC COMPLEXITY (>10 branches)
   - Methods with many if/else/switch/try branches
   - These are unmaintainable and untestable
   - SHOW: exact method, current complexity estimate, how to split it
   - Extract each branch into a named private method

2. DEEP NESTING (>3 levels)
   - if { if { foreach { if { ... } } } }
   - Hard to read, easy to introduce bugs
   - SHOW: guard clause / early return refactoring

3. LARGE CLASSES (>250 lines)
   - Classes trying to do too many things
   - SHOW: which methods belong in which new class

4. DUPLICATE CODE BLOCKS
   - The same 5+ line block appearing in multiple places
   - SHOW: extract to a shared method, trait, or service

5. DEAD CODE
   - Methods never called, variables never used, commented-out blocks
   - SHOW: exact lines to delete

6. MAGIC NUMBERS AND STRINGS
   - Unexplained numbers: if ($attempts > 5), sleep(86400)
   - SHOW: exact constant to define: const MAX_ATTEMPTS = 5;

7. MISSING PHP 8.1 TYPE DECLARATIONS
   - Public/protected methods with no return types or param types
   - These cause silent bugs and make IDEs useless
   - SHOW: exact type to add for each method

8. POOR NAMING
   - $a, $temp, $data, $arr — variables that tell you nothing
   - doThing(), process(), handle() — methods with no meaning
   - SHOW: suggested rename with reasoning

9. TODO / FIXME DEBT
   - Comments that indicate known broken or incomplete behavior
   - SHOW: what needs to be done, estimated effort

10. MISSING ERROR HANDLING
    - try { ... } catch (Exception $e) {} — swallowed exceptions
    - External calls without timeout or error handling
    - SHOW: proper exception handling with logging

For EVERY finding provide:
- Why it hurts the team right now
- Exact refactored code
- Estimated hours to fix

Return ONLY valid JSON:
{
  "agent": "tech_debt",
  "tech_debt_score": 0-100,
  "findings": [
    {
      "category": "high_complexity|deep_nesting|large_class|duplication|dead_code|magic_numbers|missing_types|poor_naming|todo_debt|missing_error_handling",
      "severity": "critical|high|medium|low",
      "title": "Specific, actionable debt title",
      "description": "Why this hurts: maintainability, testability, onboarding cost",
      "file": "app/Services/PaymentService.php",
      "line_start": 120,
      "line_end": 180,
      "code_snippet": "exact problematic code",
      "recommendation": "Exact refactoring steps",
      "code_before": "current code",
      "code_after": "improved code",
      "estimated_effort_hours": 2
    }
  ],
  "summary": {
    "total_issues": 0,
    "critical": 0, "high": 0, "medium": 0, "low": 0,
    "estimated_total_effort_hours": 0,
    "biggest_debt": "The debt item costing the most developer time right now"
  }
}
PROMPT;
    }

    protected function buildUserPrompt(array $context): string
    {
        $type      = $context['project_type'] ?? 'laravel';
        $name      = $context['project_name'] ?? 'Project';
        $files     = $this->prepareFiles($context['files'] ?? []);
        $apiFilter = $context['api_filter']   ?? null;
        $fileCount = count($context['files']  ?? []);

        $routeLine = $apiFilter
            ? "Scope: API endpoint '{$apiFilter}' — audit the code on this request path.\n"
            : '';

        return <<<PROMPT
Act as a Senior Software Engineer performing a technical debt audit before a major release.
Prioritize debt by: how much it slows the team, how likely it is to cause bugs, how hard it makes onboarding.
Do NOT flag cosmetic issues. Only flag debt that genuinely hurts — things that cause bugs or slow down development.

Project  : {$name} ({$type})
Files    : {$fileCount}
{$routeLine}
What to find (see system prompt for detail):
- High cyclomatic complexity (>10 branches): unmaintainable and untestable methods
- Deep nesting (>3 levels): use guard clauses / early returns
- Large classes (>250 lines): split by responsibility
- Duplicate code blocks (5+ identical lines): extract to shared method
- Dead code: uncalled methods, unused variables, commented-out blocks
- Magic numbers and strings: extract to named constants
- Missing PHP 8.1 type declarations: every public/protected method needs types
- Swallowed exceptions: empty catch blocks that hide failures

For every finding: explain WHY it hurts the team right now, show the exact code, show the fixed version, and estimate hours to fix.

{$this->formatFilesForPrompt($files)}
PROMPT;
    }
}
