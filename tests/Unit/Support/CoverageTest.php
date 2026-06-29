<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\Coverage;
use PHPUnit\Framework\TestCase;

class CoverageTest extends TestCase
{
    private function clover(): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <coverage>
          <project>
            <file name="/app/app/Services/PaymentService.php">
              <metrics elements="20" coveredelements="2"/>
            </file>
            <file name="/app/app/Services/WellTested.php">
              <metrics elements="10" coveredelements="10"/>
            </file>
          </project>
        </coverage>
        XML;
    }

    public function test_parses_clover_percentages(): void
    {
        $map = Coverage::fromClover($this->clover(), '/app');

        $this->assertSame(10.0, $map['app/Services/PaymentService.php']);
        $this->assertSame(100.0, $map['app/Services/WellTested.php']);
    }

    public function test_flags_complex_untested_files_only(): void
    {
        $map = Coverage::fromClover($this->clover(), '/app');
        $findings = [
            ['category' => 'high_complexity', 'file' => 'app/Services/PaymentService.php'],
            ['category' => 'high_complexity', 'file' => 'app/Services/WellTested.php'],
            ['category' => 'missing_types',   'file' => 'app/Services/Other.php'],
        ];

        $gaps = Coverage::flagUntested($map, $findings);

        $this->assertCount(1, $gaps); // only the complex + low-coverage file
        $this->assertSame('untested_complexity', $gaps[0]['category']);
        $this->assertSame('app/Services/PaymentService.php', $gaps[0]['file']);
    }

    public function test_empty_coverage_returns_no_gaps(): void
    {
        $this->assertSame([], Coverage::flagUntested([], [['category' => 'high_complexity', 'file' => 'x.php']]));
    }
}
