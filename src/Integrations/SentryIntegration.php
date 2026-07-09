<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Integrations;

class SentryIntegration implements Integration
{
    public function key(): string { return 'sentry'; }
    public function label(): string { return 'Sentry'; }
    public function icon(): string { return '▲'; }
    public function description(): string { return 'Monitor production exceptions and investigate incidents.'; }
    public function routeName(): ?string { return 'codeguardian.sentry.index'; }
    public function isAvailable(): bool { return true; }
    public function order(): int { return 10; }

    public function isConfigured(): bool
    {
        return (string) config('codeguardian.sentry.token', '') !== ''
            && (string) config('codeguardian.sentry.organization', '') !== ''
            && (string) config('codeguardian.sentry.project', '') !== '';
    }
}
