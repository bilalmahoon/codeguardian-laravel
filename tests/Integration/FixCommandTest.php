<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use Orchestra\Testbench\TestCase;

class FixCommandTest extends TestCase
{
    private string $dir;

    protected function getPackageProviders($app): array
    {
        return [\CodeGuardian\Laravel\CodeGuardianServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cg-fix-' . uniqid();
        mkdir($this->dir . '/app', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/app/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/app');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function writeFile(string $name, string $content): string
    {
        $path = $this->dir . '/app/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    /** @test */
    public function test_dry_run_does_not_modify_files(): void
    {
        $original = "<?php\nclass Foo {\n    public function bar() {\n        dd(\$x);\n        return 1;\n    }\n}\n";
        $file = $this->writeFile('Foo.php', $original);

        $this->artisan("codeguardian:fix --path={$this->dir} --dry-run")->assertExitCode(0);

        $this->assertSame($original, file_get_contents($file)); // unchanged
    }

    /** @test */
    public function test_applies_deterministic_fix_with_backup(): void
    {
        $original = "<?php\nclass Foo {\n    public function bar() {\n        dd(\$x);\n        return 1;\n    }\n}\n";
        $file = $this->writeFile('Foo.php', $original);

        $this->artisan("codeguardian:fix --path={$this->dir}")->assertExitCode(0);

        $after = (string) file_get_contents($file);
        $this->assertStringNotContainsString('dd($x);', $after);   // debug code removed
        $this->assertFileExists($file . '.cgbak');                  // backup kept
        $this->assertSame($original, (string) file_get_contents($file . '.cgbak'));

        @unlink($file . '.cgbak');
    }
}
