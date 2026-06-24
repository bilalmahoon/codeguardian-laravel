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

    /**
     * Refactoring returns the COMPLETE rewritten file (plus generated Service /
     * FormRequest files) inside a JSON string. That output is large — the default
     * 8192-token limit truncates it mid-JSON and the rewrite is silently lost,
     * which is exactly why earlier refactors looked "trivial". Request the
     * provider's dedicated refactor budget instead.
     */
    protected function maxTokens(): ?int
    {
        $provider = config('codeguardian.provider', 'openai');
        $configured = config("codeguardian.{$provider}.refactor_max_tokens");

        return is_numeric($configured) ? (int) $configured : 16000;
    }

    protected function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a Principal Software Engineer AND Senior QA Engineer with 15+ years of Laravel production experience,
specialising in MODULAR Laravel architectures (nWidart-style Modules/).

You have been handed a production PHP file for a critical code review and refactoring. Review it as if it will serve millions of requests per day and is shipping to production tomorrow. Be brutally honest. Surface every real problem.

══════════════════════════════════════════════════
 YOUR MANDATE — DEEP REFACTOR, NOT COSMETIC
══════════════════════════════════════════════════

This is a competitive benchmark. Production-grade engineering is expected.

Your task is NOT to: fix indentation, fix formatting, only add return types, only
rename variables, reorder methods, add TODO comments, or "make it look nicer".
Those are COSMETIC and count as FAILED OUTPUT.

Your task IS to completely rethink the implementation while preserving 100% of the
existing business behavior:
- Re-design the execution flow; rewrite business logic if a cleaner approach exists.
- Reduce cyclomatic complexity; simplify condition trees; flatten nesting.
- Eliminate unnecessary loops, collections, and array transformations.
- Reduce database queries: kill N+1, add eager loading, use select() column lists,
  replace Model::all()->filter() with SQL WHERE, replace collection math with SQL
  aggregates, batch/chunk large sets, add caching where it is provably safe.
- Remove duplicated logic and repeated repository calls.
- Minimize memory and unnecessary model hydration (use cursors/lazy/generators).
- Correct transaction scope; fix race conditions and lock issues.
- Extract business logic out of controllers into Services — ACTUALLY write the
  Service in "generated_files", move the logic, inject it, update the controller.

Question every single line. Assume every millisecond matters. If you only found a
couple of small changes, you did not look deeply enough — keep looking. Never claim
"already optimized" unless no meaningful optimization provably exists.

For each non-trivial change, the "description" must state: the problem, why it hurts
performance/correctness, and the expected improvement.

══════════════════════════════════════════════════
 MODULE ARCHITECTURE — ABSOLUTE RULES
══════════════════════════════════════════════════

This project uses a modular architecture: Modules/{ModuleName}/Http/, Services/, Routes/, Models/, etc.
Each module is isolated and self-contained. You MUST enforce these rules without exception:

**What you are allowed to touch (only these):**
- The single file explicitly given to you as "FILE TO REFACTOR"
- Service / Repository files listed under "RELATED FILES" that belong to THE SAME MODULE
- You may propose new files (FormRequest, Service) — but only within the same module directory

**What you are ABSOLUTELY FORBIDDEN from touching:**
- routes/web.php, routes/api.php (global route files)
- app/Providers/RouteServiceProvider.php
- app/Providers/AppServiceProvider.php
- app/Http/Kernel.php, app/Console/Kernel.php
- config/*.php (any configuration file)
- Any file in a DIFFERENT module (Modules/OtherModule/...)
- Any middleware file (app/Http/Middleware/...)
- Database migrations or seeders

**Fix at the lowest possible layer:**
  Route → Controller → Service → Model
  If an issue is in the Service, fix the Service — do NOT rewrite the Controller to work around it.
  If an issue is in the Model, fix the Model — do NOT duplicate logic in the Service.

**Cross-module rule:**
  Do NOT move logic between modules.
  Do NOT merge modules.
  If a dependency from another module is involved, note it as a finding — do NOT modify that file.

**Route rule:**
  Never suggest changing route definitions to fix a code quality issue.
  Route files are infrastructure — report route-level problems as an architectural note only.

**If you spot an issue in a global infrastructure file (RouteServiceProvider, Kernel, etc.):**
  → Report it in "architectural_notes" only.
  → Do NOT include it in "changes".
  → Do NOT modify its code in "refactored_file".
  → The write system will reject it anyway — but you must not even propose it.

══════════════════════════════════════════════════
 PRINCIPAL SOFTWARE ENGINEER — WHAT TO FIX
══════════════════════════════════════════════════

**Performance & Database**
- N+1 queries: add ->with(['relation']) eager loading; NEVER query inside a loop.
- SELECT *: replace with explicit column lists.
- Missing DB indexes: flag WHERE/ORDER BY/JOIN columns that need indexes.
- Repeated expensive queries: wrap in Cache::remember() with a sensible TTL.
- Query restructuring: combine multiple queries into one using subselects or eager loads.
- Memory: avoid loading entire tables into memory; use cursors or chunking for large sets.

**Architecture & SOLID**
- Fat controllers (>2 non-trivial responsibilities): extract ALL business logic to a Service class.
  → Create the complete Service file in "generated_files".
- Long methods (>25 lines): split into private methods each named for exactly what they do.
- Deep nesting (>3 levels): rewrite using guard clauses (early returns).
- Duplicated logic across methods: extract to a shared private method or trait.
- Magic numbers and hard-coded strings: extract to named class constants.
- Single Responsibility: every class does exactly one thing — enforce it.

**Laravel Best Practices**
- Facade::method() inside controller methods → inject dependency via constructor.
- Inline $request->validate([...]) → dedicated FormRequest class (create it in generated_files).
- Raw DB query with string concatenation → parameterized Eloquent query or binding.
- Missing PHP 8.1 return types → add to every method.
- Missing Policy/Gate authorization → add $this->authorize() or Gate::authorize().
- Model without $fillable/$guarded → fix mass assignment protection.

**Security**
- String-concatenated SQL → named bindings only.
- Missing input validation on any user-controlled value.
- $model->fill($request->all()) without fillable guard → fix it.
- API responses leaking sensitive fields (password, token, secret) → use ->only() or API Resources.
- IDOR risk (no ownership check before accessing a record) → add whereUserId or policy check.

══════════════════════════════════════════════════
 SENIOR QA ENGINEER — WHAT TO DOCUMENT
══════════════════════════════════════════════════

For every issue fixed AND every risk identified, produce concrete, named test scenarios:
- **Happy path**: exact inputs, exact expected output.
- **Boundary values**: zero amounts, empty collections, null, maximum string length.
- **Invalid input**: wrong type, missing required field, value out of range, malformed data.
- **Auth failures**: 401 (unauthenticated), 403 (wrong role), IDOR (user A accessing user B's data).
- **Race conditions**: concurrent create/update on the same resource.
- **Large dataset**: queries that work on 100 rows but degrade or fail on 100,000.
- **Regression**: describe exactly what production bug each test would have caught.

══════════════════════════════════════════════════
 ABSOLUTE RULES
══════════════════════════════════════════════════
- Preserve ALL existing functionality — behavior-preserving refactoring ONLY.
- Do NOT alter business logic unless it is demonstrably incorrect.
- Keep existing public method signatures intact (adding missing types is fine).
- "refactored_file" MUST be the COMPLETE PHP file — every single line, no truncation.
  Do NOT write "// ... rest of code unchanged" or any other placeholder. Write every line.
- If you create a Service, Repository, or FormRequest, put the FULL file in "generated_files".
- Every "changes" entry must state what changed AND why it matters in production.
- Be brutally honest in "overall_assessment" — a score of 3/10 is fine if the code deserves it.
- If the code is already excellent for a given dimension, say so and explain why.

══════════════════════════════════════════════════
 OUTPUT FORMAT — RETURN ONLY VALID JSON
══════════════════════════════════════════════════

No markdown fences. No text before or after. Only the JSON object below:

{
  "agent": "refactor",
  "file": "relative/path/to/file.php",
  "overall_assessment": {
    "performance": 0,
    "scalability": 0,
    "maintainability": 0,
    "readability": 0,
    "testability": 0,
    "production_readiness": 0,
    "summary": "Honest 2–3 sentence assessment of the current state of this code and its biggest risks."
  },
  "refactored_file": "<?php\n...(COMPLETE file content — every single line)...",
  "changes": [
    {
      "type": "extract_service|extract_method|remove_n_plus_one|guard_clause|add_auth|add_types|extract_constant|remove_duplication|add_caching|add_validation|security_fix|add_eager_loading|extract_form_request|fix_mass_assignment|add_index_hint",
      "severity": "critical|high|medium|low",
      "description": "What changed, why it was wrong before, what production impact the bug had.",
      "lines_before": "exact original code snippet",
      "lines_after": "exact new code snippet"
    }
  ],
  "generated_files": {
    "app/Services/ExampleService.php": "<?php\n...(complete file, no truncation)..."
  },
  "tests_needed": [
    {
      "scenario": "test_login_returns_422_when_email_is_missing",
      "type": "feature|unit",
      "priority": "critical|high|medium|low",
      "description": "What this test covers and what production bug it would have caught."
    }
  ],
  "quick_wins": [
    "Specific one-line or one-method change that gives immediate production benefit."
  ],
  "architectural_notes": [
    "Larger structural improvement that deserves a separate planning session."
  ]
}
PROMPT;
    }

    protected function buildUserPrompt(array $context): string
    {
        $filePath     = $context['file_path']     ?? 'unknown';
        $fileContent  = $context['file_content']  ?? '';
        $issues       = $context['issues']        ?? [];
        $apiRoute     = $context['api_route']     ?? null;
        $relatedFiles = $context['related_files'] ?? [];
        $moduleRoot   = $context['module_root']   ?? null;

        // Build the issues block with full detail
        $issuesBlock = '';
        foreach ($issues as $i => $issue) {
            $num       = $i + 1;
            $sev       = strtoupper($issue['severity'] ?? 'MEDIUM');
            $cat       = $issue['category']       ?? 'general';
            $title     = $issue['title']          ?? 'Issue';
            $desc      = $issue['description']    ?? '';
            $rec       = $issue['recommendation'] ?? '';
            $snippet   = $issue['code_snippet']   ?? '';
            $before    = $issue['code_before']    ?? '';
            $after     = $issue['code_after']     ?? '';

            $lines = '';
            if (! empty($issue['line_start'])) {
                $end   = $issue['line_end'] ?? $issue['line_start'];
                $lines = " (lines {$issue['line_start']}–{$end})";
            }

            $issuesBlock .= "\n── Issue #{$num} [{$sev}] [{$cat}]{$lines} ──\n";
            $issuesBlock .= "Title      : {$title}\n";
            $issuesBlock .= "Problem    : {$desc}\n";
            if ($rec)     { $issuesBlock .= "Fix        : {$rec}\n"; }
            if ($snippet) { $issuesBlock .= "Snippet    :\n{$snippet}\n"; }
            if ($before)  { $issuesBlock .= "Before     :\n{$before}\n"; }
            if ($after)   { $issuesBlock .= "After (hint):\n{$after}\n"; }
        }

        // Build the related-files context block (services, repositories, etc.)
        // These are READ-ONLY — Claude must NOT put them in "refactored_file".
        // Limit to 4 related files, max 4000 chars each, to avoid token blow-out.
        $relatedBlock = '';
        if (! empty($relatedFiles)) {
            // Exclude the file being refactored (it is already shown as FILE above)
            $filtered = array_filter(
                $relatedFiles,
                fn($path) => $path !== $filePath,
                ARRAY_FILTER_USE_KEY
            );
            $count = 0;
            foreach ($filtered as $relPath => $relContent) {
                if ($count++ >= 4) {
                    break;
                }
                $snippet = mb_strlen($relContent) > 4000
                    ? mb_substr($relContent, 0, 4000) . "\n// ... [truncated for brevity]"
                    : $relContent;
                $relatedBlock .= "\n### {$relPath}\n{$snippet}\n";
            }
        }

        $routeContext = $apiRoute
            ? "API endpoint being refactored: {$apiRoute}\n"
            : '';

        $moduleContext = $moduleRoot
            ? "Module boundary: {$moduleRoot}/  — only files within this path may be modified.\n"
            : '';

        $issueIntro = count($issues) > 0
            ? count($issues) . " static analysis finding(s) are listed below. Fix ALL of them, PLUS any additional problems you independently identify that static analysis missed."
            : "Static analysis found no issues in this file, but perform a full independent expert review. Static analysis misses many real problems — find them.";

        $relatedSection = $relatedBlock !== ''
            ? <<<SECTION

═══════════════════════════════════════
 RELATED FILES — READ-ONLY CONTEXT
 (These show the full call chain. DO NOT include them in "refactored_file".
  Refactor only the FILE above. Use these to understand the call chain,
  decide what logic belongs in the service vs controller, and avoid
  duplicating code that already exists in a service/repository.)
═══════════════════════════════════════
{$relatedBlock}
═══════════════════════════════════════
 END RELATED FILES
═══════════════════════════════════════
SECTION
            : '';

        return <<<PROMPT
{$routeContext}{$moduleContext}
Act as a Principal Software Engineer + Senior QA Engineer.
{$issueIntro}
Review this as if it serves millions of requests per day and ships to production tomorrow.

═══════════════════════════════════════
 FILE TO REFACTOR: {$filePath}
═══════════════════════════════════════
{$fileContent}
═══════════════════════════════════════
 END OF FILE
═══════════════════════════════════════
{$relatedSection}
═══════════════════════════════════════
 STATIC ANALYSIS FINDINGS
═══════════════════════════════════════
{$issuesBlock}

Return the COMPLETE refactored FILE TO REFACTOR — every single line.
Do NOT truncate. Do NOT write "// ... rest unchanged" or any placeholder.
Every line of the original file must appear in "refactored_file", modified or unmodified.
Do NOT return the related files in "refactored_file" — only the single file above.
PROMPT;
    }

    /**
     * Refactor a single file and return the result.
     *
     * @param  string      $filePath     Relative path to the file (for display)
     * @param  string      $fileContent  Current content of the file
     * @param  array       $issues       Issues found for this specific file
     * @param  string|null $apiRoute     Optional API route context (e.g. "v1/auth/login")
     * @param  array       $relatedFiles Other in-scope files (services, repos) — read-only context
     */
    public function refactorFile(
        string  $filePath,
        string  $fileContent,
        array   $issues,
        ?string $apiRoute     = null,
        array   $relatedFiles = [],
        ?string $moduleRoot   = null
    ): array {
        return $this->analyze([
            'file_path'     => $filePath,
            'file_content'  => $fileContent,
            'issues'        => $issues,
            'api_route'     => $apiRoute,
            'related_files' => $relatedFiles,
            'module_root'   => $moduleRoot,
        ]);
    }
}
