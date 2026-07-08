<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\SafeFileWriter;
use PHPUnit\Framework\TestCase;

class SafeFileWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cg_safewrite_' . uniqid();
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_valid_write_applies_and_keeps_backup(): void
    {
        $file = $this->dir . '/a.php';
        file_put_contents($file, "<?php\n// old\n");

        // Validator that always accepts.
        $writer = new SafeFileWriter(fn() => null);
        $res    = $writer->write($file, "<?php\n// new\n");

        $this->assertTrue($res['ok']);
        $this->assertSame("<?php\n// new\n", file_get_contents($file));
        $this->assertNotNull($res['backup']);
        $this->assertFileExists($res['backup']);
        $this->assertSame("<?php\n// old\n", file_get_contents($res['backup']));
    }

    public function test_invalid_write_rolls_back_to_original(): void
    {
        $file = $this->dir . '/b.php';
        file_put_contents($file, "<?php\n// original\n");

        // Validator that always rejects.
        $writer = new SafeFileWriter(fn() => 'syntax error, unexpected token');
        $res    = $writer->write($file, "<?php\nbroken(((");

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('syntax error', (string) $res['error']);
        $this->assertSame("<?php\n// original\n", file_get_contents($file), 'Original must be restored');
    }

    public function test_invalid_write_of_new_file_removes_it(): void
    {
        $file = $this->dir . '/new.php';
        $writer = new SafeFileWriter(fn() => 'nope');
        $res    = $writer->write($file, "<?php\nbroken");

        $this->assertFalse($res['ok']);
        $this->assertFileDoesNotExist($file, 'A never-valid new file must not be left on disk');
    }

    public function test_noop_write_is_success_without_backup(): void
    {
        $file = $this->dir . '/c.php';
        file_put_contents($file, "<?php\nsame\n");

        $writer = new SafeFileWriter(fn() => null);
        $res    = $writer->write($file, "<?php\nsame\n");

        $this->assertTrue($res['ok']);
        $this->assertNull($res['backup']);
    }

    public function test_default_validator_uses_real_php_lint(): void
    {
        $file = $this->dir . '/lint.php';
        file_put_contents($file, "<?php\necho 1;\n");

        $writer = new SafeFileWriter();
        $ok     = $writer->write($file, "<?php\necho 42;\n");
        $this->assertTrue($ok['ok']);

        $bad = $writer->write($file, "<?php\nfunction (((");
        $this->assertFalse($bad['ok'], 'Broken PHP must be rejected by php -l');
        $this->assertSame("<?php\necho 42;\n", file_get_contents($file), 'Must roll back to last good content');
    }

    public function test_restore_from_backup(): void
    {
        $file   = $this->dir . '/d.php';
        $backup = $this->dir . '/d.bak';
        file_put_contents($file, "current");
        file_put_contents($backup, "backup-content");

        $writer = new SafeFileWriter(fn() => null);
        $this->assertTrue($writer->restore($file, $backup));
        $this->assertSame('backup-content', file_get_contents($file));
    }
}
