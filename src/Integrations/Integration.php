<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Integrations;

/**
 * A CodeGuardian integration = one external system (Sentry, Slack, and in the
 * future Grafana, Jira, GitHub, …) surfaced as a first-class section of the
 * dashboard.
 *
 * Integrations are registered in an {@see IntegrationRegistry} and the navigation
 * is built from them, so a new integration becomes a new nav item + page WITHOUT
 * touching the layout or existing pages. This is the extension point: implement
 * this interface, register it, ship a view — nothing else changes.
 */
interface Integration
{
    /** Stable machine key, e.g. "sentry". Used in routes and lookups. */
    public function key(): string;

    /** Human label shown in the nav, e.g. "Sentry". */
    public function label(): string;

    /** A short glyph/emoji shown next to the label. */
    public function icon(): string;

    /** One-line description for tooltips / setup screens. */
    public function description(): string;

    /**
     * The named route for this integration's landing page, or null when the
     * integration is a "coming soon" placeholder (rendered disabled in the nav).
     */
    public function routeName(): ?string;

    /** Whether the integration is live (true) or a "coming soon" teaser (false). */
    public function isAvailable(): bool;

    /** Whether the user has supplied the credentials this integration needs. */
    public function isConfigured(): bool;

    /** Sort weight in the navigation (lower = earlier). */
    public function order(): int;
}
