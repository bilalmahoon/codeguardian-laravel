<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Integrations;

use CodeGuardian\Laravel\Integrations\ComingSoonIntegration;
use CodeGuardian\Laravel\Integrations\Integration;
use CodeGuardian\Laravel\Integrations\IntegrationRegistry;
use PHPUnit\Framework\TestCase;

class IntegrationRegistryTest extends TestCase
{
    private function fake(string $key, int $order, bool $available = true): Integration
    {
        return new class($key, $order, $available) implements Integration {
            public function __construct(private string $k, private int $o, private bool $a) {}
            public function key(): string { return $this->k; }
            public function label(): string { return ucfirst($this->k); }
            public function icon(): string { return '*'; }
            public function description(): string { return 'desc'; }
            public function routeName(): ?string { return 'r.' . $this->k; }
            public function isAvailable(): bool { return $this->a; }
            public function isConfigured(): bool { return false; }
            public function order(): int { return $this->o; }
        };
    }

    public function test_all_is_sorted_by_order(): void
    {
        $reg = (new IntegrationRegistry())
            ->register($this->fake('b', 20))
            ->register($this->fake('a', 10));

        $keys = array_map(fn($i) => $i->key(), $reg->all());
        $this->assertSame(['a', 'b'], $keys);
    }

    public function test_get_and_has(): void
    {
        $reg = (new IntegrationRegistry())->register($this->fake('sentry', 10));
        $this->assertTrue($reg->has('sentry'));
        $this->assertFalse($reg->has('nope'));
        $this->assertSame('sentry', $reg->get('sentry')->key());
        $this->assertNull($reg->get('nope'));
    }

    public function test_nav_items_expose_route_only_when_available(): void
    {
        $reg = (new IntegrationRegistry())
            ->register($this->fake('sentry', 10, true))
            ->register(new ComingSoonIntegration('jira', 'Jira', '🧭', 'soon', 40));

        $nav = $reg->navItems();

        $this->assertSame('r.sentry', $nav[0]['route']);
        $this->assertTrue($nav[0]['available']);

        $this->assertSame('jira', $nav[1]['key']);
        $this->assertNull($nav[1]['route']);
        $this->assertFalse($nav[1]['available']);
    }
}
