<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Support\AiClient;
use CodeGuardian\Laravel\Support\GitChanges;
use CodeGuardian\Laravel\Support\GitDiff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * AI code review of ONLY the changed lines (a git diff) — the cheapest, most
 * relevant way to use AI in a PR. Instead of re-reviewing whole files, it sends
 * the diff hunks to the model for a senior-engineer review.
 */
class ReviewCommand extends Command
{
    protected $signature = 'codeguardian:review
                            {--path=     : Project root (default: base_path())}
                            {--since=    : Diff against this git ref (e.g. main, origin/main). Default: working tree vs HEAD}
                            {--output=   : Write the review markdown to this file}
                            {--max-lines=200 : Max changed lines to send per file}';

    protected $description = 'AI review of changed lines only (diff-aware, low-cost PR review)';

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a Principal Software Engineer performing a focused pull-request review.
You are given a unified-diff style summary of ONLY the changed lines (prefixed
with + for additions and - for removals), grouped by file.

Review strictly the changes shown. For each issue you find, be specific and
actionable. Prioritize: correctness/logic bugs, security, performance (N+1,
unbounded queries), error handling, and breaking changes. Skip style nits
unless they hide a real bug.

Respond in concise Markdown:
- A one-line overall verdict (Approve / Approve with comments / Request changes).
- A bulleted list of findings. For each: the file, a short title, the severity
  (critical/high/medium/low), why it matters, and the concrete fix.
- If the diff looks safe, say so briefly. Do not invent issues.
PROMPT;

    public function handle(): int
    {
        $path     = $this->option('path') ?: base_path();
        $repoRoot = GitChanges::repoRoot($path) ?? $path;
        $since    = (string) ($this->option('since') ?: '');

        $this->info('🔎 CodeGuardian AI diff review');
        $this->newLine();

        $diff = GitDiff::fetch($repoRoot, $since !== '' ? $since : null);
        if ($diff === null) {
            $this->error('  Could not read git diff (not a git repo, or git unavailable).');
            return self::FAILURE;
        }

        $parsed = GitDiff::parseUnifiedDiff($diff);
        // Only review source files worth reviewing.
        $parsed = array_filter(
            $parsed,
            fn($_, $file) => preg_match('/\.(php|dart|js|ts|vue)$/i', (string) $file),
            ARRAY_FILTER_USE_BOTH
        );

        if ($parsed === []) {
            $this->info('  ✅ No reviewable source changes found.');
            return self::SUCCESS;
        }

        $this->line('  Changed files: ' . count($parsed));
        foreach (array_keys($parsed) as $f) {
            $this->line("     • {$f}");
        }
        $this->newLine();

        if (! AiClient::hasApiKey()) {
            $this->warn('  ⚠ No AI API key configured (set CODEGUARDIAN_CLAUDE_KEY).');
            $this->line('  Falling back to a static scan of the changed files:');
            $this->newLine();
            return $this->call('codeguardian:analyze', [
                '--path'    => $path,
                '--changed' => true,
                '--plain'   => true,
            ]);
        }

        $maxLines = (int) ($this->option('max-lines') ?: 200);
        $context  = GitDiff::toReviewContext($parsed, $maxLines);

        $this->line('  Asking AI for a focused review...');
        $client = new AiClient();
        try {
            $review = $client->complete(self::SYSTEM_PROMPT, "Changed code to review:\n\n" . $context);
        } catch (\Throwable $e) {
            $this->error('  AI review failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->line($review);

        if ($outFile = $this->option('output')) {
            File::ensureDirectoryExists(dirname($outFile));
            File::put($outFile, $review . "\n");
            $this->newLine();
            $this->info("  📄 Review written: {$outFile}");
        }

        return self::SUCCESS;
    }
}
