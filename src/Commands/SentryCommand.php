<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Agents\BugFixAgent;
use CodeGuardian\Laravel\Analyzers\StaticOrchestrator;
use CodeGuardian\Laravel\Support\AiClient;
use CodeGuardian\Laravel\Support\SafeFileWriter;
use CodeGuardian\Laravel\Support\SentryClient;
use CodeGuardian\Laravel\Support\SentryStateStore;
use CodeGuardian\Laravel\Support\TestRunner;
use CodeGuardian\Laravel\Support\WebhookNotifier;
use Illuminate\Console\Command;

/**
 * Pull unresolved production issues from Sentry, trace each to the exact
 * offending file, analyze it, optionally generate + safely apply an AI fix,
 * verify with tests, notify Slack, and optionally mark the issue resolved.
 *
 *   php artisan codeguardian:sentry                 # triage: locate + analyze
 *   php artisan codeguardian:sentry --fix           # + generate AI fixes (preview)
 *   php artisan codeguardian:sentry --fix --apply --with-tests   # write + verify
 *   php artisan codeguardian:sentry --fix --apply --with-tests --resolve --slack
 */
class SentryCommand extends Command
{
    protected $signature = 'codeguardian:sentry
                            {--limit=10       : Max unresolved issues to pull}
                            {--issue=         : Handle a single Sentry issue by ID (e.g. from a Slack button)}
                            {--fix            : Generate an AI fix for each issue (requires an AI key)}
                            {--apply          : Write the generated fix to disk (safe: syntax-checked + backup + rollback)}
                            {--with-tests     : After --apply, run existing project tests; roll back on failure}
                            {--resolve        : Mark the issue resolved in Sentry after a verified fix}
                            {--slack=         : Send a Slack summary (URL, or empty to use config webhook)}
                            {--project=       : Project label shown in the Slack message}
                            {--watch          : Continuously poll Sentry and auto-handle NEW issues only}
                            {--interval=60    : Seconds between polls in --watch mode}
                            {--max-iterations=0 : Stop --watch after N polls (0 = run forever)}
                            {--dry-run        : Never write, resolve, or send — print what would happen}';

    protected $description = 'Triage Sentry production issues and (optionally) auto-fix them safely';

    public function handle(): int
    {
        // Resolved from the container so tests can inject a client backed by a
        // mocked HTTP handler; defaults to SentryClient::fromConfig().
        $sentry = app(SentryClient::class);

        if (! $sentry->configured()) {
            $this->error('Sentry is not configured. Set: ' . implode(', ', $sentry->missingConfig()));
            $this->line('Add them to .env — see config/codeguardian.php → sentry.');
            return self::FAILURE;
        }

        $wantFix = (bool) $this->option('fix');
        $dryRun  = (bool) $this->option('dry-run');
        $apply   = (bool) $this->option('apply') && ! $dryRun;

        if ($wantFix && ! AiClient::hasApiKey()) {
            $this->error('--fix needs an AI provider key. Set CODEGUARDIAN_MODE + provider key, or drop --fix for triage-only.');
            return self::FAILURE;
        }

        $root = base_path();

        // Single-issue mode (e.g. triggered by a Slack "Fix" button).
        if (($issueId = (string) $this->option('issue')) !== '') {
            $issue = $sentry->issue($issueId);
            if ($issue === null) {
                $this->error("Issue {$issueId} not found in Sentry.");
                return self::FAILURE;
            }
            $item = $this->processIssue($sentry, $issue, $root, $wantFix, $apply, $dryRun);
            $this->renderTable([$item]);
            $this->maybeNotify([$item], $dryRun);
            return $this->needsAttention([$item]) ? self::FAILURE : self::SUCCESS;
        }

        // Continuous observe + auto-fix loop.
        if ((bool) $this->option('watch')) {
            return $this->watch($sentry, $root, $wantFix, $apply, $dryRun);
        }

        // One-shot batch.
        $items = $this->runBatch($sentry, $root, $wantFix, $apply, $dryRun);
        if ($items === null) {
            $this->info('✓ No unresolved issues found. Nothing to do.');
            return self::SUCCESS;
        }
        $this->renderTable($items);
        $this->maybeNotify($items, $dryRun);

        return $this->needsAttention($items) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Poll Sentry, handle each unresolved issue, and return the result items
     * (or null when there was nothing to do). Honours the state store so an
     * issue already handled by a previous poll is skipped.
     *
     * @return array<int,array<string,mixed>>|null
     */
    private function runBatch(SentryClient $sentry, string $root, bool $wantFix, bool $apply, bool $dryRun, ?SentryStateStore $state = null): ?array
    {
        $limit  = max(1, (int) $this->option('limit'));
        $issues = $sentry->unresolvedIssues($limit);

        if ($state !== null) {
            $issues = array_values(array_filter(
                $issues,
                fn($i) => is_array($i) && ! $state->isProcessed((string) ($i['id'] ?? ''))
            ));
        }

        if ($issues === []) {
            return null;
        }

        $items = [];
        foreach ($issues as $issue) {
            $item = $this->processIssue($sentry, $issue, $root, $wantFix, $apply, $dryRun);
            $items[] = $item;

            // Record in the state store unless it was a transient error (so a
            // failed event fetch is retried on the next poll).
            if ($state !== null && $item['status'] !== 'error') {
                $state->markProcessed((string) ($issue['id'] ?? ''), (string) $item['status']);
            }
        }

        return $items;
    }

    /**
     * Continuous watch loop: poll Sentry every --interval seconds and auto-handle
     * only issues not seen before. Runs forever unless --max-iterations is set.
     */
    private function watch(SentryClient $sentry, string $root, bool $wantFix, bool $apply, bool $dryRun): int
    {
        $interval = max(5, (int) $this->option('interval'));
        $maxIter  = max(0, (int) $this->option('max-iterations'));
        $state    = SentryStateStore::fromConfig();

        $this->info("👁  Watching Sentry every {$interval}s — auto-handling new issues. Press Ctrl+C to stop.");

        $iteration = 0;
        while (true) {
            $iteration++;
            $items = $this->runBatch($sentry, $root, $wantFix, $apply, $dryRun, $state);

            if ($items === null) {
                $this->line('  · ' . date('H:i:s') . ' — no new issues');
            } else {
                $this->renderTable($items);
                $this->maybeNotify($items, $dryRun);
            }

            if ($maxIter > 0 && $iteration >= $maxIter) {
                return self::SUCCESS;
            }

            sleep($interval);
        }
    }

    /** @param array<int,array<string,mixed>> $items */
    private function needsAttention(array $items): bool
    {
        foreach ($items as $i) {
            if (in_array($i['status'], ['unresolvable', 'no-file', 'error'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param  array<string,mixed> $issue
     * @return array<string,mixed>
     */
    private function processIssue(
        SentryClient $sentry,
        array $issue,
        string $root,
        bool $wantFix,
        bool $apply,
        bool $dryRun
    ): array {
        $s    = SentryClient::summariseIssue($issue);
        $item = [
            'id'        => $s['id'],
            'title'     => $s['title'],
            'permalink' => $s['permalink'],
            'events'    => $s['count'],
            'status'    => 'no-file',
            'file'      => null,
            'line'      => null,
            'tests'     => '',
            'root_cause'=> '',
        ];

        $this->line("\n• {$s['title']}" . ($s['count'] ? "  ({$s['count']}× in prod)" : ''));

        $event = $sentry->latestEvent($s['id']);
        if ($event === null) {
            $item['status']     = 'error';
            $item['root_cause'] = 'Could not fetch the latest event from Sentry.';
            $this->warn('  ✗ could not fetch event');
            return $item;
        }

        $exception = SentryClient::exceptionOf($event);
        $frame     = SentryClient::culpritFrame($event);
        $rel       = $frame ? SentryClient::resolveLocalPath($frame['filename'], $root) : null;

        if ($rel === null) {
            $this->warn('  ✗ offending file not found in this repo' . ($frame ? " ({$frame['filename']})" : ''));
            $item['root_cause'] = trim("{$exception['type']}: {$exception['value']}");
            return $item;
        }

        $item['file'] = $rel;
        $item['line'] = $frame['lineno'] ?: null;
        $this->line("  → {$rel}" . ($frame['lineno'] ? ":{$frame['lineno']}" : ''));

        $content = (string) @file_get_contents($root . '/' . $rel);

        // Static triage: surface CodeGuardian findings on the offending file.
        $findings = $this->staticFindings($rel, $content);
        if ($findings !== []) {
            $this->line('  ' . count($findings) . ' CodeGuardian finding(s) on this file');
        }

        if (! $wantFix) {
            $item['status']     = 'analyzed';
            $item['root_cause'] = trim("{$exception['type']}: {$exception['value']}");
            return $item;
        }

        // AI targeted fix.
        $result = $this->generateFix($rel, $content, $exception, $frame, $s['count'], $event);

        if (($result['error'] ?? null) !== null) {
            $item['status']     = 'error';
            $item['root_cause'] = (string) $result['error'];
            $this->warn('  ✗ ' . $result['error']);
            return $item;
        }

        $item['root_cause'] = (string) ($result['root_cause'] ?? '');

        if (($result['cannot_fix'] ?? false) === true) {
            $item['status']     = 'unresolvable';
            $item['root_cause'] = trim((string) ($result['reason'] ?: $result['root_cause']));
            $this->warn('  ⚠ AI could not fix safely: ' . $item['root_cause']);
            return $item;
        }

        $fixedFile = (string) ($result['fixed_file'] ?? '');
        if (trim($fixedFile) === '') {
            $item['status']     = 'error';
            $item['root_cause'] = 'AI returned no fixed file content.';
            return $item;
        }

        // Save a preview copy regardless of whether we apply.
        $previewPath = $this->savePreview($rel, $fixedFile);
        $this->line("  fix ready" . ($previewPath ? " · preview: {$previewPath}" : ''));

        if (! $apply) {
            $item['status'] = 'preview';
            return $item;
        }

        return $this->applyFix($sentry, $s['id'], $rel, $root, $fixedFile, $item, $dryRun);
    }

    /**
     * Write the fix safely, optionally verify with tests, optionally resolve.
     *
     * @param  array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function applyFix(
        SentryClient $sentry,
        string $issueId,
        string $rel,
        string $root,
        string $fixedFile,
        array $item,
        bool $dryRun
    ): array {
        $abs    = $root . '/' . $rel;
        $writer = new SafeFileWriter();
        $write  = $writer->write($abs, $fixedFile);

        if (! $write['ok']) {
            $item['status']     = 'unresolvable';
            $item['root_cause'] = 'Generated fix failed validation (rolled back): ' . $write['error'];
            $this->warn('  ✗ fix rejected by syntax check — rolled back');
            return $item;
        }

        $this->info('  ✓ applied (backup: ' . basename((string) $write['backup']) . ')');

        // Optional test verification with rollback on regression.
        if ((bool) $this->option('with-tests')) {
            $tests = (new TestRunner($root))->runExistingProjectTests();
            if (($tests['skipped'] ?? false) === true) {
                $item['tests'] = 'skipped';
            } elseif ((int) ($tests['failed'] ?? 0) > 0) {
                $item['tests'] = 'failed';
                if ($write['backup'] && $writer->restore($abs, (string) $write['backup'])) {
                    $this->warn('  ✗ tests failed — rolled back the fix');
                }
                $item['status']     = 'unresolvable';
                $item['root_cause'] = 'Fix broke existing tests and was rolled back.';
                return $item;
            } else {
                $item['tests'] = 'passed';
                $this->info('  ✓ existing tests still pass');
            }
        }

        $item['status'] = 'fixed';

        // Mark resolved in Sentry only for a verified fix.
        if ((bool) $this->option('resolve') && ! $dryRun && $item['tests'] !== 'failed') {
            if ($sentry->resolveIssue($issueId)) {
                $this->info('  ✓ marked resolved in Sentry');
            } else {
                $this->warn('  ⚠ could not mark resolved in Sentry (check event:write scope)');
            }
        }

        return $item;
    }

    /**
     * @param  array{type:string,value:string} $exception
     * @param  array<string,mixed>|null        $frame
     * @param  array<string,mixed>             $event
     * @return array<string,mixed>
     */
    private function generateFix(string $rel, string $content, array $exception, ?array $frame, int $count, array $event): array
    {
        try {
            return (new BugFixAgent())->fix([
                'file_path'    => $rel,
                'file_content' => $content,
                'error_type'   => $exception['type'],
                'error_value'  => $exception['value'],
                'line'         => $frame['lineno'] ?? 0,
                'function'     => $frame['function'] ?? '',
                'culprit'      => (string) ($event['culprit'] ?? ''),
                'stack_trace'  => $this->traceForPrompt($frame),
                'event_count'  => $count,
            ]);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** Compact code-context block from the culprit frame for the AI prompt. */
    private function traceForPrompt(?array $frame): string
    {
        if ($frame === null || empty($frame['context'])) {
            return '';
        }
        $lines = [];
        foreach ($frame['context'] as $pair) {
            $lines[] = sprintf('%5d | %s', $pair[0], rtrim($pair[1]));
        }
        return implode("\n", $lines);
    }

    /**
     * Run the static engine on a single file and return its findings.
     *
     * @return array<int,array<string,mixed>>
     */
    private function staticFindings(string $rel, string $content): array
    {
        if (trim($content) === '' || ! str_ends_with($rel, '.php')) {
            return [];
        }
        try {
            $result = app(StaticOrchestrator::class)->analyze([$rel => $content]);
        } catch (\Throwable) {
            return [];
        }
        return array_values(array_filter(
            $result['all_findings'] ?? [],
            fn($f) => is_array($f) && (($f['file'] ?? null) === $rel)
        ));
    }

    private function savePreview(string $rel, string $content): ?string
    {
        try {
            $dir = storage_path('codeguardian/sentry');
            @mkdir($dir, 0775, true);
            $name = str_replace(['/', '\\'], '_', $rel) . '.proposed.php';
            $path = $dir . '/' . $name;
            return @file_put_contents($path, $content) !== false ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<int,array<string,mixed>> $items */
    private function renderTable(array $items): void
    {
        $rows = [];
        foreach ($items as $it) {
            $rows[] = [
                $this->statusLabel((string) $it['status']),
                $this->clip((string) $it['title'], 42),
                (string) ($it['file'] ?? '—') . ($it['line'] ? ':' . $it['line'] : ''),
                (string) ($it['tests'] ?: '—'),
            ];
        }
        $this->line('');
        $this->table(['Status', 'Issue', 'Location', 'Tests'], $rows);
    }

    /** @param array<int,array<string,mixed>> $items */
    private function maybeNotify(array $items, bool $dryRun): void
    {
        $slackOpt = $this->option('slack');
        // --slack can be given with a URL (--slack=…) or as a bare flag
        // (--slack, value null) meaning "use the configured webhook". Detect the
        // bare-flag case via the raw input so it isn't mistaken for "not passed".
        $provided = $slackOpt !== null || $this->input->hasParameterOption('--slack');
        if (! $provided) {
            return;
        }
        $url = (string) ($slackOpt ?: config('codeguardian.notifications.webhook', ''));
        if ($url === '') {
            $this->warn('--slack given but no URL and no config webhook set — skipping notification.');
            return;
        }

        $project     = (string) ($this->option('project') ?: config('app.name', ''));
        $interactive = (bool) config('codeguardian.slack.enabled', false)
                    && (bool) config('codeguardian.slack.interactive', true);
        $payload     = WebhookNotifier::sentrySummary($items, $project, $interactive);

        if ($dryRun) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }

        $this->info(WebhookNotifier::send($url, $payload) ? '✓ Slack notified.' : '⚠ Slack notification failed.');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'fixed'        => '<info>fixed</info>',
            'preview'      => '<comment>preview</comment>',
            'analyzed'     => 'analyzed',
            'unresolvable' => '<comment>needs-fix</comment>',
            'no-file'      => '<comment>no-file</comment>',
            default        => '<error>error</error>',
        };
    }

    private function clip(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
