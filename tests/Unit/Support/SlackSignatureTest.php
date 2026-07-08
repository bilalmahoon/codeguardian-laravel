<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\SlackSignature;
use PHPUnit\Framework\TestCase;

class SlackSignatureTest extends TestCase
{
    private string $secret = 'shhh-signing-secret';

    public function test_valid_signature_passes(): void
    {
        $now  = 1_700_000_000;
        $body = 'token=abc&command=%2Fcodeguardian&text=sentry';
        $sig  = SlackSignature::compute($this->secret, $now, $body);

        $this->assertTrue(
            SlackSignature::verify($this->secret, (string) $now, $sig, $body, $now)
        );
    }

    public function test_tampered_body_fails(): void
    {
        $now  = 1_700_000_000;
        $body = 'text=sentry';
        $sig  = SlackSignature::compute($this->secret, $now, $body);

        $this->assertFalse(
            SlackSignature::verify($this->secret, (string) $now, $sig, 'text=sentry-fix', $now)
        );
    }

    public function test_wrong_secret_fails(): void
    {
        $now  = 1_700_000_000;
        $body = 'text=sentry';
        $sig  = SlackSignature::compute('other-secret', $now, $body);

        $this->assertFalse(
            SlackSignature::verify($this->secret, (string) $now, $sig, $body, $now)
        );
    }

    public function test_replayed_old_timestamp_fails(): void
    {
        $ts   = 1_700_000_000;
        $body = 'text=sentry';
        $sig  = SlackSignature::compute($this->secret, $ts, $body);

        // "now" is 10 minutes after the signed timestamp → outside the window.
        $now = $ts + 600;
        $this->assertFalse(
            SlackSignature::verify($this->secret, (string) $ts, $sig, $body, $now)
        );
    }

    public function test_empty_inputs_fail(): void
    {
        $this->assertFalse(SlackSignature::verify('', '1', 'v0=x', 'b', 1));
        $this->assertFalse(SlackSignature::verify($this->secret, '', 'v0=x', 'b', 1));
        $this->assertFalse(SlackSignature::verify($this->secret, '1', '', 'b', 1));
    }

    public function test_non_numeric_timestamp_fails(): void
    {
        $this->assertFalse(
            SlackSignature::verify($this->secret, 'not-a-number', 'v0=x', 'b', 1)
        );
    }
}
