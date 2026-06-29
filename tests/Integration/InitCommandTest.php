<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use Orchestra\Testbench\TestCase;

class InitCommandTest extends TestCase
{
    private string $dir;

    protected function getPackageProviders($app): array
    {
        return [\CodeGuardian\Laravel\CodeGuardianServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cg-init-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    /** @test */
    public function test_init_publishes_config_and_github_ci(): void
    {
        $this->artisan("codeguardian:init --dir={$this->dir} --ci=github")
            ->assertExitCode(0);

        $this->assertFileExists($this->dir . '/config/codeguardian.php');
        $this->assertFileExists($this->dir . '/.github/workflows/codeguardian.yml');
    }

    /** @test */
    public function test_init_gitlab_ci(): void
    {
        $this->artisan("codeguardian:init --dir={$this->dir} --ci=gitlab")->assertExitCode(0);
        $this->assertFileExists($this->dir . '/codeguardian.gitlab-ci.yml');
    }

    /** @test */
    public function test_init_does_not_overwrite_without_force(): void
    {
        @mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/codeguardian.php', '<?php return ["sentinel" => true];');

        $this->artisan("codeguardian:init --dir={$this->dir} --ci=none")->assertExitCode(0);

        $this->assertStringContainsString('sentinel', (string) file_get_contents($this->dir . '/config/codeguardian.php'));
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
