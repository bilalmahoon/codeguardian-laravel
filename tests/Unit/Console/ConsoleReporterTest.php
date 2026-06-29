<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Console;

use CodeGuardian\Laravel\Console\ConsoleReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\StreamOutput;

class ConsoleReporterTest extends TestCase
{
    private function stages(): array
    {
        return [
            ['key' => 'security', 'label' => 'Security Analysis'],
            ['key' => 'performance', 'label' => 'Performance Analysis'],
        ];
    }

    public function test_non_decorated_logs_one_line_per_transition(): void
    {
        $out      = new BufferedOutput();
        $reporter = new ConsoleReporter($out, $this->stages(), 'CG', false);

        $reporter->start('security', 3);
        $reporter->advance('security', 'app/Foo.php'); // ignored in plain mode
        $reporter->finish('security', '2 findings');
        $reporter->start('performance', 3);
        $reporter->finish('performance', '0 findings');

        $text = $out->fetch();
        $this->assertStringContainsString('Security Analysis', $text);
        $this->assertStringContainsString('2 findings', $text);
        $this->assertStringContainsString('Performance Analysis', $text);
        // plain mode must not emit ANSI cursor-movement sequences
        $this->assertStringNotContainsString("\x1b[2K", $text);
    }

    public function test_non_decorated_marks_failure(): void
    {
        $out      = new BufferedOutput();
        $reporter = new ConsoleReporter($out, $this->stages(), 'CG', false);

        $reporter->start('security');
        $reporter->fail('security', 'crashed');

        $this->assertStringContainsString('crashed', $out->fetch());
    }

    public function test_execution_stats_prints_breakdown(): void
    {
        $out      = new BufferedOutput();
        $reporter = new ConsoleReporter($out, $this->stages(), 'CG', false);
        $reporter->setCount('files_total', 5);

        $reporter->start('security', 5);
        $reporter->finish('security', '1 findings');
        $reporter->start('performance', 5);
        $reporter->finish('performance', '0 findings');
        $reporter->executionStats();

        $text = $out->fetch();
        $this->assertStringContainsString('Execution stats', $text);
        $this->assertStringContainsString('Total', $text);
        $this->assertStringContainsString('5 files', $text);
    }

    public function test_decorated_mode_emits_ansi_without_error(): void
    {
        $stream = fopen('php://memory', 'r+');
        $out    = new StreamOutput($stream, StreamOutput::VERBOSITY_NORMAL, true); // decorated
        $reporter = new ConsoleReporter($out, $this->stages(), 'CG', true);
        $reporter->setCount('files_total', 2);

        $reporter->start('security', 2);
        $reporter->advance('security', 'app/A.php');
        $reporter->advance('security', 'app/B.php');
        $reporter->finish('security', '1 findings');
        $reporter->start('performance', 2);
        $reporter->finish('performance', '0 findings');
        $reporter->done();

        rewind($stream);
        $text = stream_get_contents($stream);
        fclose($stream);

        // Live mode repaints with cursor moves / line clears.
        $this->assertStringContainsString("\x1b[2K", $text);
        $this->assertStringContainsString('Security Analysis', $text);
    }
}
