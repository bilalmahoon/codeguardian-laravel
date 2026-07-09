<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Integrations;

/**
 * Central catalogue of every {@see Integration}. The dashboard navigation and
 * the integrations index are both derived from here, so adding an integration
 * is a one-liner (`$registry->register(new FooIntegration())`) — no layout or
 * routing edits required.
 */
class IntegrationRegistry
{
    /** @var array<string,Integration> */
    private array $integrations = [];

    public function register(Integration $integration): self
    {
        $this->integrations[$integration->key()] = $integration;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->integrations[$key]);
    }

    public function get(string $key): ?Integration
    {
        return $this->integrations[$key] ?? null;
    }

    /**
     * All integrations, ordered for display.
     *
     * @return array<int,Integration>
     */
    public function all(): array
    {
        $list = array_values($this->integrations);
        usort($list, fn(Integration $a, Integration $b) => $a->order() <=> $b->order());

        return $list;
    }

    /**
     * Flattened nav metadata for the layout — pure data, no objects, so views
     * stay dumb and the shape is easy to test.
     *
     * @return array<int,array{key:string,label:string,icon:string,description:string,route:?string,available:bool,configured:bool}>
     */
    public function navItems(): array
    {
        return array_map(fn(Integration $i) => [
            'key'         => $i->key(),
            'label'       => $i->label(),
            'icon'        => $i->icon(),
            'description' => $i->description(),
            'route'       => $i->isAvailable() ? $i->routeName() : null,
            'available'   => $i->isAvailable(),
            'configured'  => $i->isConfigured(),
        ], $this->all());
    }
}
