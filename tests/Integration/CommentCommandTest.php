<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use Orchestra\Testbench\TestCase;

class CommentCommandTest extends TestCase
{
    private string $report;

    protected function getPackageProviders($app): array
    {
        return [\CodeGuardian\Laravel\CodeGuardianServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->report = sys_get_temp_dir() . '/cg-comment-' . uniqid() . '.json';
        file_put_contents($this->report, json_encode([
            'project_name'  => 'acme/api',
            'overall_score' => 72,
            'grade'         => 'B',
            'summary'       => ['total_issues' => 2, 'critical' => 0, 'high' => 1, 'medium' => 1, 'low' => 0],
            'all_findings'  => [
                ['severity' => 'high', 'title' => 'N+1 query', 'file' => 'app/C.php', 'line_start' => 7],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->report);
        parent::tearDown();
    }

    /** @test */
    public function test_dry_run_prints_body(): void
    {
        $this->artisan("codeguardian:comment --report={$this->report} --dry-run")
            ->expectsOutputToContain('acme/api')
            ->expectsOutputToContain('**Total** | **2**')
            ->assertExitCode(0);
    }

    /** @test */
    public function test_missing_report_fails(): void
    {
        $this->artisan('codeguardian:comment --report=/tmp/does-not-exist-xyz.json --dry-run')
            ->assertExitCode(1);
    }

    /** @test */
    public function test_no_platform_without_dry_run_fails(): void
    {
        // No CI env, no --platform → cannot post.
        $this->artisan("codeguardian:comment --report={$this->report}")
            ->assertExitCode(1);
    }
}
