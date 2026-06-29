<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\ExternalFindings;
use PHPUnit\Framework\TestCase;

class ExternalFindingsTest extends TestCase
{
    public function test_parses_phpstan_json(): void
    {
        $json = json_encode([
            'totals' => ['errors' => 0, 'file_errors' => 1],
            'files'  => [
                '/app/app/Foo.php' => [
                    'errors'   => 1,
                    'messages' => [
                        ['message' => 'Undefined variable $x', 'line' => 12, 'ignorable' => true],
                    ],
                ],
            ],
        ]);

        $findings = ExternalFindings::fromJson($json, '/app');

        $this->assertCount(1, $findings);
        $this->assertSame('phpstan', $findings[0]['category']);
        $this->assertSame('app/Foo.php', $findings[0]['file']);
        $this->assertSame(12, $findings[0]['line_start']);
        $this->assertStringContainsString('PHPStan', $findings[0]['title']);
    }

    public function test_parses_psalm_json(): void
    {
        $json = json_encode([
            ['type' => 'PossiblyNullReference', 'message' => 'Possibly null', 'file_name' => 'app/Bar.php', 'line_from' => 5, 'severity' => 'error'],
            ['type' => 'UnusedVariable', 'message' => 'Unused', 'file_name' => 'app/Bar.php', 'line_from' => 9, 'severity' => 'info'],
        ]);

        $findings = ExternalFindings::fromJson($json);

        $this->assertCount(2, $findings);
        $this->assertSame('psalm', $findings[0]['category']);
        $this->assertSame('high', $findings[0]['severity']); // error → high
        $this->assertSame('low', $findings[1]['severity']);  // info → low
    }

    public function test_invalid_json_returns_empty(): void
    {
        $this->assertSame([], ExternalFindings::fromJson('not json'));
        $this->assertSame([], ExternalFindings::fromJson('{}'));
    }
}
