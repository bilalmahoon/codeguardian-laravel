<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\ConfigValidator;
use PHPUnit\Framework\TestCase;

class ConfigValidatorTest extends TestCase
{
    public function test_valid_config_has_no_errors(): void
    {
        $result = ConfigValidator::validate([
            'mode'     => 'static',
            'provider' => 'claude',
            'preset'   => 'balanced',
            'output'   => ['format' => 'both'],
            'gates'    => ['max_critical' => 0],
        ]);

        $this->assertSame([], $result['errors']);
    }

    public function test_invalid_mode_and_provider_are_errors(): void
    {
        $result = ConfigValidator::validate(['mode' => 'turbo', 'provider' => 'skynet']);

        $this->assertNotEmpty($result['errors']);
        $joined = implode("\n", $result['errors']);
        $this->assertStringContainsString("mode 'turbo'", $joined);
        $this->assertStringContainsString("provider 'skynet'", $joined);
    }

    public function test_ai_mode_without_key_warns(): void
    {
        $result = ConfigValidator::validate([
            'mode'     => 'ai',
            'provider' => 'claude',
            'claude'   => ['key' => ''],
        ]);

        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('no API key', implode("\n", $result['warnings']));
    }

    public function test_invalid_gate_key_and_value(): void
    {
        $result = ConfigValidator::validate([
            'mode' => 'static', 'provider' => 'openai',
            'gates' => ['max_bogus' => 1, 'max_critical' => 'lots'],
        ]);

        $joined = implode("\n", $result['errors']);
        $this->assertStringContainsString('gates.max_bogus', $joined);
        $this->assertStringContainsString('gates.max_critical must be a number', $joined);
    }

    public function test_malformed_custom_rule_and_bad_regex(): void
    {
        $result = ConfigValidator::validate([
            'mode' => 'static', 'provider' => 'openai',
            'custom_rules' => [
                ['title' => 'no id', 'pattern' => 'x'],          // missing id
                ['id' => 'r', 'title' => 't', 'pattern' => '('], // bad regex
            ],
        ]);

        $joined = implode("\n", $result['errors']);
        $this->assertStringContainsString("missing required 'id'", $joined);
        $this->assertStringContainsString('not a valid regex', $joined);
    }

    public function test_invalid_rule_severity_override(): void
    {
        $result = ConfigValidator::validate([
            'mode' => 'static', 'provider' => 'openai',
            'rules' => ['n_plus_one' => 'urgent'],
        ]);

        $this->assertStringContainsString("severity 'urgent'", implode("\n", $result['errors']));
    }
}
