<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\RuleRegistry;
use PHPUnit\Framework\TestCase;

class RuleRegistryPresetTest extends TestCase
{
    public function test_balanced_is_empty(): void
    {
        $this->assertSame([], RuleRegistry::preset('balanced'));
        $this->assertSame([], RuleRegistry::preset('unknown'));
    }

    public function test_strict_upgrades_severities(): void
    {
        $preset = RuleRegistry::preset('strict');
        $this->assertSame('high', $preset['n_plus_one']);
        $this->assertSame('medium', $preset['missing_types']);
    }

    public function test_lenient_disables_noisy_rules(): void
    {
        $preset = RuleRegistry::preset('lenient');
        $this->assertFalse($preset['magic_numbers']);
        $this->assertFalse($preset['missing_types']);
    }

    public function test_user_rules_override_preset(): void
    {
        // Simulate AnalyzeCommand's merge: preset UNDER user rules.
        $preset = RuleRegistry::preset('lenient');           // magic_numbers => false
        $user   = ['magic_numbers' => 'high'];               // user wants it loud
        $spec   = RuleRegistry::fromConfig(array_merge($preset, $user));

        $this->assertTrue($spec['magic_numbers']['enabled']);
        $this->assertSame('high', $spec['magic_numbers']['severity']);
    }
}
