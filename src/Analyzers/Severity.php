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

    /** Return a severity level clamped to a known value. */
    public static function clamp(string $severity): string
    {
        return in_array($severity, [self::CRITICAL, self::HIGH, self::MEDIUM, self::LOW], true)
            ? $severity
            : self::MEDIUM;
    }

    private function __construct() {}
}
