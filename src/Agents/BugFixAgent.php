<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Agents;

/**
 * Produces a TARGETED fix for a specific production exception reported by Sentry.
 *
 * Unlike RefactorAgent (which deeply rethinks a file for quality), this agent is
 * surgical: given the exception type/message, the offending file + line, and the
 * stack trace, it makes the smallest correct change that eliminates the crash
 * while preserving all existing behaviour. It returns the complete rewritten file
 * so the caller can syntax-validate and safely apply it.
 */
class BugFixAgent extends BasePackageAgent
{
    public function getName(): string
    {
        return 'bugfix';
    }

    protected function maxTokens(): ?int
    {
        $provider   = config('codeguardian.provider', 'openai');
        $configured = config("codeguardian.{$provider}.refactor_max_tokens");

        return is_numeric($configured) ? (int) $configured : 16000;
    }

    protected function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a Principal Software Engineer doing production incident response on a Laravel (PHP 8.1+) application.
A live exception was captured by Sentry. You are given the exact error, the offending file, the crash line, and the stack trace.

══════════════════════════════════════════════════
 YOUR MANDATE — SURGICAL BUG FIX, NOT A REFACTOR
══════════════════════════════════════════════════
- Find the ROOT CAUSE of THIS specific exception. Do not guess; reason from the error type, message, and stack trace.
- Make the SMALLEST correct change that eliminates the crash for all inputs that triggered it.
- Preserve 100% of existing behaviour for every non-crashing path. Do NOT reformat, rename, reorder,
  add types everywhere, or "improve" unrelated code. That is OUT OF SCOPE and counts as a failed fix.
- Prefer fixing at the correct layer (guard a null, validate input, fix a wrong type/argument, handle the
  missing key, add the missing relation/return). Add defensive handling only where it is genuinely correct.
- If the true fix belongs in a DIFFERENT file than the one shown (e.g. the caller), still return this file
  unchanged and explain in "root_cause" + set "cannot_fix": true with the reason and the file that needs changing.

══════════════════════════════════════════════════
 SAFETY
══════════════════════════════════════════════════
- The result will be syntax-checked and the project's tests re-run; a change that breaks tests is auto-rolled-back.
- Never introduce new dependencies, migrations, or config changes.
- Never swallow errors silently to "make it go away" — fix the actual cause. Only catch/guard when that is the correct behaviour.
- "fixed_file" MUST be the COMPLETE file — every line, no "// ... unchanged" placeholders.

══════════════════════════════════════════════════
 OUTPUT — RETURN ONLY VALID JSON (no markdown, no prose)
══════════════════════════════════════════════════
{
  "agent": "bugfix",
  "root_cause": "Precise, technical explanation of why THIS exception happened.",
  "confidence": "high|medium|low",
  "risk": "low|medium|high",
  "cannot_fix": false,
  "reason": "Only when cannot_fix is true: what is needed and in which file.",
  "fixed_file": "<?php\n...(COMPLETE file content — every single line)...",
  "changes": [
    { "description": "What changed and why it stops the crash.", "lines_before": "exact original snippet", "lines_after": "exact new snippet" }
  ],
  "tests_needed": [
    { "scenario": "test_name_describing_the_regression", "type": "feature|unit", "description": "What this test asserts and the production bug it guards against." }
  ]
}
PROMPT;
    }

    protected function buildUserPrompt(array $context): string
    {
        $filePath  = (string) ($context['file_path'] ?? 'unknown');
        $content   = (string) ($context['file_content'] ?? '');
        $errType   = (string) ($context['error_type'] ?? 'Error');
        $errValue  = (string) ($context['error_value'] ?? '');
        $line      = (int) ($context['line'] ?? 0);
        $function  = (string) ($context['function'] ?? '');
        $culprit   = (string) ($context['culprit'] ?? '');
        $trace     = (string) ($context['stack_trace'] ?? '');
        $events    = (int) ($context['event_count'] ?? 0);

        $lineNote = $line > 0 ? " (around line {$line}" . ($function !== '' ? ", in {$function}()" : '') . ')' : '';
        $freq     = $events > 0 ? "This error has occurred {$events} time(s) in production.\n" : '';
        $traceBlk = $trace !== ''
            ? "\n═══ STACK TRACE (oldest → crash site) ═══\n{$trace}\n"
            : '';
        $culpritBlk = $culprit !== '' ? "Sentry culprit: {$culprit}\n" : '';

        return <<<PROMPT
A production exception was captured by Sentry. Fix its ROOT CAUSE with the smallest correct change.

═══ EXCEPTION ═══
Type    : {$errType}
Message : {$errValue}
Location: {$filePath}{$lineNote}
{$culpritBlk}{$freq}{$traceBlk}
═══════════════════════════════════════
 OFFENDING FILE: {$filePath}
═══════════════════════════════════════
{$content}
═══════════════════════════════════════
 END OF FILE
═══════════════════════════════════════

Return the COMPLETE fixed file in "fixed_file" — every line, modified or not.
Keep the change surgical and behaviour-preserving. If the real fix belongs in another file,
set "cannot_fix": true and explain where in "reason".
PROMPT;
    }

    /**
     * Convenience wrapper: fix one file for one Sentry exception.
     *
     * @param  array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function fix(array $context): array
    {
        return $this->analyze($context);
    }
}
