<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

/**
 * Typed severity constants — eliminates magic strings scattered across analyzers.
 * Use these everywhere instead of inline string literals.
 */
final class Severity
{
    public const CRITICAL = 'critical';
    public const HIGH     = 'high';
    public const MEDIUM   = 'medium';
    public const LOW      = 'low';

    /** Deduction weights used when calculating analyzer scores. */
    public const WEIGHTS = [
        self::CRITICAL => 20,
        self::HIGH     => 10,
        self::MEDIUM   => 5,
        self::LOW      => 2,
    ];

    /** Sort order for severity (lower = more severe). */
    public const ORDER = [
        self::CRITICAL => 0,
        self::HIGH     => 1,
        self::MEDIUM   => 2,
        self::LOW      => 3,
    ];

    /** Indicative CVSS v3.1 base-score bands per severity (for reporting only). */
    public const CVSS_BAND = [
        self::CRITICAL => '9.0–10.0',
        self::HIGH     => '7.0–8.9',
        self::MEDIUM   => '4.0–6.9',
        self::LOW      => '0.1–3.9',
    ];

    /** Return a severity level clamped to a known value. */
    public static function clamp(string $severity): string
    {
        return in_array($severity, [self::CRITICAL, self::HIGH, self::MEDIUM, self::LOW], true)
            ? $severity
            : self::MEDIUM;
    }

    /**
     * Is $severity at least as severe as $minimum?
     * e.g. atLeast('high', 'medium') === true; atLeast('low', 'high') === false.
     */
    public static function atLeast(string $severity, string $minimum): bool
    {
        $s = self::ORDER[self::clamp($severity)] ?? 3;
        $m = self::ORDER[self::clamp($minimum)]  ?? 3;
        return $s <= $m;
    }

    /** All severities, most-severe first. */
    public static function all(): array
    {
        return [self::CRITICAL, self::HIGH, self::MEDIUM, self::LOW];
    }

    private function __construct() {}
}
