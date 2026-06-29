<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\Diagnostics;
use PHPUnit\Framework\TestCase;

class DiagnosticsTest extends TestCase
{
    private function healthyEnv(array $over = []): array
    {
        return array_merge([
            'php_version'          => '8.2.0',
            'extensions'           => ['json', 'tokenizer', 'mbstring', 'curl'],
            'mode'                 => 'static',
            'provider'             => 'openai',
            'has_api_key'          => false,
            'writable'             => [
                'reports' => ['path' => '/tmp/reports', 'writable' => true],
            ],
            'phpunit_available'    => true,
            'config_published'     => true,
            'dashboard_enabled'    => true,
            'dashboard_local_only' => true,
            'app_env'              => 'local',
            'dashboard_middleware' => ['web'],
            'modules_detected'     => 'none',
        ], $over);
    }

    private function byId(array $checks, string $id): array
    {
        foreach ($checks as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        $this->fail("check {$id} not found");
    }

    public function test_healthy_env_has_no_failures(): void
    {
        $checks = Diagnostics::evaluate($this->healthyEnv());
        $counts = Diagnostics::summarize($checks);
        $this->assertSame(0, $counts['fail']);
    }

    public function test_old_php_fails(): void
    {
        $checks = Diagnostics::evaluate($this->healthyEnv(['php_version' => '8.0.0']));
        $this->assertSame('fail', $this->byId($checks, 'php_version')['status']);
    }

    public function test_missing_extension_fails_with_fix(): void
    {
        $checks = Diagnostics::evaluate($this->healthyEnv(['extensions' => ['json', 'mbstring']]));
        $tok = $this->byId($checks, 'ext_tokenizer');
        $this->assertSame('fail', $tok['status']);
        $this->assertNotEmpty($tok['fix']);
    }

    public function test_ai_mode_without_key_fails(): void
    {
        $checks = Diagnostics::evaluate($this->healthyEnv(['mode' => 'hybrid', 'has_api_key' => false, 'provider' => 'claude']));
        $ai = $this->byId($checks, 'ai_config');
        $this->assertSame('fail', $ai['status']);
        $this->assertStringContainsString('CLAUDE', $ai['fix']);
    }

    public function test_ai_mode_with_key_passes(): void
    {
        $checks = Diagnostics::evaluate($this->healthyEnv(['mode' => 'ai', 'has_api_key' => true]));
        $this->assertSame('pass', $this->byId($checks, 'ai_config')['status']);
    }

    public function test_static_mode_passes_without_key(): void
    {
        $checks = Diagnostics::evaluate($this->healthyEnv(['mode' => 'static', 'has_api_key' => false]));
        $this->assertSame('pass', $this->byId($checks, 'ai_config')['status']);
    }

    public function test_non_writable_path_fails(): void
    {
        $checks = Diagnostics::evaluate($this->healthyEnv([
            'writable' => ['reports' => ['path' => '/root/x', 'writable' => false]],
        ]));
        $this->assertSame('fail', $this->byId($checks, 'writable_reports')['status']);
    }

    public function test_missing_phpunit_warns(): void
    {
        $checks = Diagnostics::evaluate($this->healthyEnv(['phpunit_available' => false]));
        $this->assertSame('warn', $this->byId($checks, 'phpunit')['status']);
    }

    public function test_dashboard_exposed_in_production_warns(): void
    {
        $checks = Diagnostics::evaluate($this->healthyEnv([
            'app_env' => 'production', 'dashboard_enabled' => true, 'dashboard_local_only' => false,
        ]));
        $this->assertSame('warn', $this->byId($checks, 'dashboard')['status']);
    }

    public function test_dashboard_local_only_in_production_passes(): void
    {
        $checks = Diagnostics::evaluate($this->healthyEnv([
            'app_env' => 'production', 'dashboard_enabled' => true, 'dashboard_local_only' => true,
        ]));
        $this->assertSame('pass', $this->byId($checks, 'dashboard')['status']);
    }
}
