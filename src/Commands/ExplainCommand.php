<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Support\AiClient;
use CodeGuardian\Laravel\Support\RuleDocs;
use Illuminate\Console\Command;

/**
 * Explains a detection rule in depth: what it is, why it matters, how to fix it,
 * and references. With --ai (and a key configured) it asks the model for a
 * tailored, example-driven explanation on top of the built-in docs.
 */
class ExplainCommand extends Command
{
    protected $signature = 'codeguardian:explain
                            {rule : The rule id / finding category (e.g. n_plus_one, sql_injection)}
                            {--ai  : Augment with an AI deep-dive (requires an API key)}
                            {--json : Output the built-in documentation as JSON}';

    protected $description = 'Explain a detection rule — what it means, why it matters, and how to fix it';

    public function handle(): int
    {
        $rule = (string) $this->argument('rule');
        $doc  = RuleDocs::for($rule);

        if ($this->option('json')) {
            $this->line((string) json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if (! RuleDocs::has($rule)) {
            $this->warn("  No built-in documentation for '{$rule}'. Showing a generic template.");
            $this->line("  Run `php artisan codeguardian:rules` to see all known rule ids.");
            $this->newLine();
        }

        $this->info("  📘 {$doc['title']}");
        $this->line("     Rule id: {$doc['id']}   ·   Group: {$doc['group']}");
        $this->newLine();
        $this->line('  WHY IT MATTERS');
        $this->line('  ' . $doc['why']);
        $this->newLine();
        $this->line('  HOW TO FIX');
        $this->line('  ' . $doc['fix']);

        if (! empty($doc['refs'])) {
            $this->newLine();
            $this->line('  REFERENCES');
            foreach ($doc['refs'] as $ref) {
                $this->line('  • ' . $ref);
            }
        }

        if ($this->option('ai')) {
            $this->aiDeepDive($doc);
        }

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $doc */
    private function aiDeepDive(array $doc): void
    {
        $this->newLine();
        if (! AiClient::hasApiKey()) {
            $this->warn('  ⚠ --ai requested but no API key configured (set CODEGUARDIAN_CLAUDE_KEY).');
            return;
        }

        $this->line('  🤖 AI deep-dive...');
        $system = 'You are a Principal Software Engineer mentoring a developer. Explain the given '
            . 'Laravel/PHP code-quality rule concisely: a realistic bad example, the fixed version, '
            . 'and one subtle gotcha. Use short Markdown with fenced code blocks.';
        $user = "Rule: {$doc['title']} ({$doc['id']})\nWhy: {$doc['why']}\nFix guidance: {$doc['fix']}";

        try {
            $answer = (new AiClient())->complete($system, $user);
            $this->newLine();
            $this->line($answer);
        } catch (\Throwable $e) {
            $this->warn('  AI deep-dive failed: ' . $e->getMessage());
        }
    }
}
