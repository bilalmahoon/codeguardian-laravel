<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Verifies that an incoming request genuinely came from Slack.
 *
 * Slack signs every request: it sends `X-Slack-Request-Timestamp` and
 * `X-Slack-Signature: v0=<hex hmac>` where the HMAC-SHA256 is computed over
 * "v0:{timestamp}:{rawBody}" using the app's Signing Secret. We recompute it and
 * compare in constant time, and reject stale timestamps to block replay attacks.
 *
 * Pure and deterministic — no framework, no clock except the injected $now.
 *
 * @see https://api.slack.com/authentication/verifying-requests-from-slack
 */
final class SlackSignature
{
    /** Reject requests whose timestamp is older/newer than this many seconds. */
    public const REPLAY_WINDOW = 300;

    public static function compute(string $signingSecret, int $timestamp, string $rawBody): string
    {
        $base = 'v0:' . $timestamp . ':' . $rawBody;
        return 'v0=' . hash_hmac('sha256', $base, $signingSecret);
    }

    /**
     * @param  int $now  Current unix time (injectable for deterministic tests).
     */
    public static function verify(
        string $signingSecret,
        string $timestampHeader,
        string $signatureHeader,
        string $rawBody,
        int $now
    ): bool {
        if ($signingSecret === '' || $timestampHeader === '' || $signatureHeader === '') {
            return false;
        }

        if (! ctype_digit(ltrim($timestampHeader, '-')) ) {
            return false;
        }
        $timestamp = (int) $timestampHeader;

        // Replay protection: the request must be recent.
        if (abs($now - $timestamp) > self::REPLAY_WINDOW) {
            return false;
        }

        $expected = self::compute($signingSecret, $timestamp, $rawBody);

        return hash_equals($expected, $signatureHeader);
    }
}
