<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Integrations;

class SlackIntegration implements Integration
{
    public function key(): string { return 'slack'; }
    public function label(): string { return 'Slack'; }
    public function icon(): string { return '#'; }
    public function description(): string { return 'Follow development alerts and investigate issues from Slack.'; }
    public function routeName(): ?string { return 'codeguardian.slack.index'; }
    public function isAvailable(): bool { return true; }
    public function order(): int { return 20; }

    public function isConfigured(): bool
    {
        return (string) config('codeguardian.slack.bot_token', '') !== ''
            && ! empty(config('codeguardian.slack.channels', []));
    }
}
