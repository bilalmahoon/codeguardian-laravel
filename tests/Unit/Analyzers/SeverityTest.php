<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Analyzers;

use CodeGuardian\Laravel\Analyzers\Severity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CodeGuardian\Laravel\Analyzers\Severity
 */
class SeverityTest extends TestCase
{
    public function test_constants_are_defined(): void
    {
        $this->assertSame('critical', Severity::CRITICAL);
        $this->assertSame('high',     Severity::HIGH);
        $this->assertSame('medium',   Severity::MEDIUM);
        $this->assertSame('low',      Severity::LOW);
    }

    public function test_weights_are_defined_for_all_severities(): void
    {
        $this->assertArrayHasKey(Severity::CRITICAL, Severity::WEIGHTS);
        $this->assertArrayHasKey(Severity::HIGH,     Severity::WEIGHTS);
        $this->assertArrayHasKey(Severity::MEDIUM,   Severity::WEIGHTS);
        $this->assertArrayHasKey(Severity::LOW,      Severity::WEIGHTS);
    }

    public function test_critical_weight_is_highest(): void
    {
        $this->assertGreaterThan(Severity::WEIGHTS[Severity::HIGH],   Severity::WEIGHTS[Severity::CRITICAL]);
        $this->assertGreaterThan(Severity::WEIGHTS[Severity::MEDIUM], Severity::WEIGHTS[Severity::HIGH]);
        $this->assertGreaterThan(Severity::WEIGHTS[Severity::LOW],    Severity::WEIGHTS[Severity::MEDIUM]);
    }

    public function test_clamp_returns_known_values(): void
    {
        $this->assertSame(Severity::CRITICAL, Severity::clamp('critical'));
        $this->assertSame(Severity::HIGH,     Severity::clamp('high'));
        $this->assertSame(Severity::MEDIUM,   Severity::clamp('medium'));
        $this->assertSame(Severity::LOW,      Severity::clamp('low'));
    }

    public function test_clamp_falls_back_to_medium_for_unknown(): void
    {
        $this->assertSame(Severity::MEDIUM, Severity::clamp(''));
        $this->assertSame(Severity::MEDIUM, Severity::clamp('unknown'));
        $this->assertSame(Severity::MEDIUM, Severity::clamp('info'));
    }

    public function test_order_values_are_ascending(): void
    {
        $this->assertLessThan(Severity::ORDER[Severity::HIGH],   Severity::ORDER[Severity::CRITICAL]);
        $this->assertLessThan(Severity::ORDER[Severity::MEDIUM], Severity::ORDER[Severity::HIGH]);
        $this->assertLessThan(Severity::ORDER[Severity::LOW],    Severity::ORDER[Severity::MEDIUM]);
    }
}
