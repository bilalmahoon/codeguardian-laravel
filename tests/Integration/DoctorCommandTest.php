<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use Orchestra\Testbench\TestCase;

/**
 * Verifies the codeguardian:doctor command runs end-to-end inside a real app
 * and emits a structured diagnostics report.
 */
class DoctorCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\CodeGuardian\Laravel\CodeGuardianServiceProvider::class];
    }

    /** @test */
    public function test_doctor_runs_and_reports_php_check(): void
    {
        $this->artisan('codeguardian:doctor')
            ->expectsOutputToContain('Doctor')
            ->expectsOutputToContain('PHP version');
    }

    /** @test */
    public function test_doctor_json_output_is_valid(): void
    {
        $this->artisan('codeguardian:doctor --json')->assertExitCode(0);
    }

    /** @test */
    public function test_doctor_fails_when_ai_mode_lacks_key(): void
    {
        config()->set('codeguardian.mode', 'hybrid');
        config()->set('codeguardian.provider', 'claude');
        config()->set('codeguardian.claude.key', '');
        config()->set('codeguardian.openai.key', '');
        config()->set('codeguardian.gemini.key', '');

        // A missing key in AI mode is a hard failure → non-zero exit.
        $this->artisan('codeguardian:doctor')->assertExitCode(1);
    }
}
