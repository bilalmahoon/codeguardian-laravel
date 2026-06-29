<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Support\WebhookNotifier;
use Illuminate\Console\Command;

/**
 * Posts a CodeGuardian summary to a chat webhook (Slack / Microsoft Teams / a
 * generic JSON endpoint) — so a CI run can ping a channel with the result.
 */
class NotifyCommand extends Command
{
    protected $signature = 'codeguardian:notify
                            {--report=    : Path to a JSON report (default: latest in the report dir)}
                            {--url=       : Webhook URL (default: config codeguardian.notifications.webhook)}
                            {--format=slack : Payload format: slack | teams | generic}
                            {--project=   : Project label to show in the message}
                            {--dry-run    : Print the payload; do not send}';

    protected $description = 'Send a CodeGuardian summary to a Slack/Teams/webhook endpoint';

    public function handle(): int
    {
        $report = $this->loadReport();
        if ($report === null) {
            $this->error('No JSON report found. Run `codeguardian:analyze --format=json` first, or pass --report=.');
            return self::FAILURE;
        }

        $format  = (string) ($this->option('format') ?: 'slack');
        $project = (string) ($this->option('project') ?: ($report['project_name'] ?? ''));
        $payload = WebhookNotifier::build($format, $report, $project);

        if ($this->option('dry-run')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $url = (string) ($this->option('url') ?: config('codeguardian.notifications.webhook', ''));
        if ($url === '') {
            $this->error('No webhook URL. Pass --url= or set config codeguardian.notifications.webhook.');
            return self::FAILURE;
        }

        $ok = WebhookNotifier::send($url, $payload);
        if (! $ok) {
            $this->error('Webhook POST failed (non-2xx or network error).');
            return self::FAILURE;
        }

        $this->info('✓ Notification sent.');
        return self::SUCCESS;
    }

    /** @return array<string,mixed>|null */
    private function loadReport(): ?array
    {
        $path = $this->option('report');
        if (! is_string($path) || $path === '') {
            $path = $this->latestJsonReport();
        }
        if ($path === null || ! is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function latestJsonReport(): ?string
    {
        $dir   = storage_path((string) config('codeguardian.output.report_dir', 'codeguardian/reports'));
        $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        return $files[0];
    }
}
