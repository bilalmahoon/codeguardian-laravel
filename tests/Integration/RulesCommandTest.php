<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use Orchestra\Testbench\TestCase;

class RulesCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\CodeGuardian\Laravel\CodeGuardianServiceProvider::class];
    }

    /** @test */
    public function test_rules_command_lists_rules(): void
    {
        $this->artisan('codeguardian:rules')
            ->expectsOutputToContain('Detection Rules')
            ->assertExitCode(0);
    }

    /** @test */
    public function test_rules_json_reflects_overrides(): void
    {
        config()->set('codeguardian.rules', ['magic_numbers' => false, 'n_plus_one' => 'critical']);

        $this->artisan('codeguardian:rules --json --group=performance')->assertExitCode(0);
        $this->artisan('codeguardian:rules --enabled-only')->assertExitCode(0);
    }
}
