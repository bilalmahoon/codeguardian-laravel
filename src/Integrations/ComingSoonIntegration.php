<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Integrations;

/**
 * A registered-but-not-yet-built integration. It appears in the nav as a
 * disabled "soon" item, demonstrating (and reserving space for) the plugin
 * model: Grafana, Jira, GitHub/Bitbucket, etc. Swap it for a real
 * {@see Integration} implementation when built — the nav updates itself.
 */
final class ComingSoonIntegration implements Integration
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $icon,
        private readonly string $description,
        private readonly int $order,
    ) {
    }

    public function key(): string { return $this->key; }
    public function label(): string { return $this->label; }
    public function icon(): string { return $this->icon; }
    public function description(): string { return $this->description; }
    public function routeName(): ?string { return null; }
    public function isAvailable(): bool { return false; }
    public function isConfigured(): bool { return false; }
    public function order(): int { return $this->order; }
}
