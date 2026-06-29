<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Console;

use CodeGuardian\Laravel\Console\ProgressFormat;
use PHPUnit\Framework\TestCase;

class ProgressFormatTest extends TestCase
{
    public function test_duration_formats_by_magnitude(): void
    {
        $this->assertSame('450ms', ProgressFormat::duration(0.45));
        $this->assertSame('3.4s', ProgressFormat::duration(3.42));
        $this->assertSame('5s', ProgressFormat::duration(5.0));
        $this->assertSame('1m 05s', ProgressFormat::duration(65));
        $this->assertSame('1h 04m', ProgressFormat::duration(3840));
    }

    public function test_duration_clamps_negative(): void
    {
        $this->assertSame('0ms', ProgressFormat::duration(-3));
    }

    public function test_percent(): void
    {
        $this->assertSame(0, ProgressFormat::percent(0, 0));
        $this->assertSame(0, ProgressFormat::percent(0, 10));
        $this->assertSame(50, ProgressFormat::percent(5, 10));
        $this->assertSame(100, ProgressFormat::percent(10, 10));
        $this->assertSame(100, ProgressFormat::percent(99, 10)); // clamped
    }

    public function test_bar_width_and_fill(): void
    {
        $this->assertSame('██████████', ProgressFormat::bar(100, 10));
        $this->assertSame('░░░░░░░░░░', ProgressFormat::bar(0, 10));
        $this->assertSame('█████░░░░░', ProgressFormat::bar(50, 10));
        $this->assertSame(10, mb_strlen(ProgressFormat::bar(33, 10)));
    }

    public function test_eta_requires_signal(): void
    {
        $this->assertSame('—', ProgressFormat::eta(0, 0, 0));
        $this->assertSame('—', ProgressFormat::eta(10, 0, 100));
        $this->assertSame('—', ProgressFormat::eta(10, 100, 100));
        // 5s elapsed for 50/100 → ~5s remaining
        $this->assertSame('5s', ProgressFormat::eta(5, 50, 100));
    }

    public function test_rate(): void
    {
        $this->assertSame('—', ProgressFormat::rate(0, 5));
        $this->assertSame('10/s', ProgressFormat::rate(1, 10));
        $this->assertSame('250/s', ProgressFormat::rate(1, 250));
    }

    public function test_shorten_path(): void
    {
        $this->assertSame('app/Models/User.php', ProgressFormat::shortenPath('app/Models/User.php', 52));
        $long = ProgressFormat::shortenPath(str_repeat('a/', 60) . 'File.php', 20);
        $this->assertSame(20, mb_strlen($long));
        $this->assertStringStartsWith('…', $long);
    }
}
