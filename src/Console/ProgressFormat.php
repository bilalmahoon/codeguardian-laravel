<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Console;

/**
 * Pure, side-effect-free formatting helpers for the premium CLI experience.
 *
 * Kept separate from the renderer so the maths (durations, percentages, ETA,
 * progress bars) is fully unit-testable without any terminal/ANSI dependency.
 */
final class ProgressFormat
{
    /** Human-friendly duration from seconds: "450ms", "3.4s", "1m 02s", "1h 04m". */
    public static function duration(float $seconds): string
    {
        if ($seconds < 0) {
            $seconds = 0.0;
        }
        if ($seconds < 1) {
            return ((int) round($seconds * 1000)) . 'ms';
        }
        if ($seconds < 60) {
            return rtrim(rtrim(number_format($seconds, 1), '0'), '.') . 's';
        }
        if ($seconds < 3600) {
            $m = (int) floor($seconds / 60);
            $s = (int) round($seconds - $m * 60);
            if ($s === 60) { $m++; $s = 0; }
            return $m . 'm ' . str_pad((string) $s, 2, '0', STR_PAD_LEFT) . 's';
        }
        $h = (int) floor($seconds / 3600);
        $m = (int) round(($seconds - $h * 3600) / 60);
        if ($m === 60) { $h++; $m = 0; }
        return $h . 'h ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . 'm';
    }

    /** Integer percent 0..100 (0 total → 0). */
    public static function percent(int $done, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }
        return (int) min(100, max(0, round($done / $total * 100)));
    }

    /**
     * Render a fixed-width progress bar using block glyphs.
     * e.g. percent(60), width(10) → "██████░░░░".
     */
    public static function bar(int $percent, int $width = 24, string $full = '█', string $empty = '░'): string
    {
        $percent = max(0, min(100, $percent));
        $width   = max(1, $width);
        $filled  = (int) round($percent / 100 * $width);
        return str_repeat($full, $filled) . str_repeat($empty, $width - $filled);
    }

    /**
     * Estimate remaining time as a duration string.
     * Returns "—" until there is enough signal (some progress on a known total).
     */
    public static function eta(float $elapsedSeconds, int $done, int $total): string
    {
        if ($total <= 0 || $done <= 0 || $done >= $total || $elapsedSeconds <= 0) {
            return '—';
        }
        $perUnit   = $elapsedSeconds / $done;
        $remaining = $perUnit * ($total - $done);
        return self::duration($remaining);
    }

    /** Throughput as "N/s" (or "—" with no signal). */
    public static function rate(float $elapsedSeconds, int $done): string
    {
        if ($elapsedSeconds <= 0 || $done <= 0) {
            return '—';
        }
        $perSecond = $done / $elapsedSeconds;
        if ($perSecond >= 100) {
            return (string) (int) round($perSecond) . '/s';
        }
        return rtrim(rtrim(number_format($perSecond, 1), '0'), '.') . '/s';
    }

    /** Truncate a path to the rightmost $max chars, prefixing an ellipsis. */
    public static function shortenPath(string $path, int $max = 52): string
    {
        $path = str_replace('\\', '/', $path);
        if (mb_strlen($path) <= $max) {
            return $path;
        }
        return '…' . mb_substr($path, -($max - 1));
    }
}
