<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use CodeGuardian\Laravel\Support\HistoryStore;
use Orchestra\Testbench\TestCase;

class TrendCommandTest extends TestCase
{
    private string $file;

    protected function getPackageProviders($app): array
    {
        return [\CodeGuardian\Laravel\CodeGuardianServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/cg-trend-' . uniqid() . '/history.jsonl';
        config()->set('codeguardian.output.history_file', $this->file);
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
            @rmdir(dirname($this->file));
        }
        parent::tearDown();
    }

    /** @test */
    public function test_trend_reports_no_history_gracefully(): void
    {
        $this->artisan('codeguardian:trend')
            ->expectsOutputToContain('No analysis history yet.')
            ->assertExitCode(0);
    }

    /** @test */
    public function test_trend_renders_history(): void
    {
        $store = new HistoryStore($this->file);
        $store->record(['project_name' => 'demo', 'overall_score' => 70, 'grade' => 'C',
            'summary' => ['total_issues' => 20, 'critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4]]);
        $store->record(['project_name' => 'demo', 'overall_score' => 85, 'grade' => 'B',
            'summary' => ['total_issues' => 8, 'critical' => 0, 'high' => 1, 'medium' => 3, 'low' => 4]]);

        $this->artisan('codeguardian:trend')
            ->expectsOutputToContain('Health Trend')
            ->assertExitCode(0);
    }
}
