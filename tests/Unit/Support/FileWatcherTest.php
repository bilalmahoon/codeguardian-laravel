<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\FileWatcher;
use PHPUnit\Framework\TestCase;

class FileWatcherTest extends TestCase
{
    public function test_diff_detects_added_modified_removed(): void
    {
        $old = ['a.php' => 100, 'b.php' => 200, 'c.php' => 300];
        $new = ['a.php' => 100, 'b.php' => 250, 'd.php' => 400];

        $diff = FileWatcher::diff($old, $new);

        $this->assertSame(['d.php'], $diff['added']);
        $this->assertSame(['b.php'], $diff['modified']);
        $this->assertSame(['c.php'], $diff['removed']);
    }

    public function test_changed_merges_added_and_modified(): void
    {
        $old = ['a.php' => 1, 'b.php' => 1];
        $new = ['a.php' => 2, 'b.php' => 1, 'c.php' => 1];

        $changed = FileWatcher::changed($old, $new);

        sort($changed);
        $this->assertSame(['a.php', 'c.php'], $changed);
    }

    public function test_snapshot_tracks_only_configured_extensions_and_skips_dirs(): void
    {
        $root = sys_get_temp_dir() . '/cg_watch_' . uniqid();
        mkdir($root . '/sub', 0775, true);
        mkdir($root . '/vendor', 0775, true);
        file_put_contents($root . '/a.php', '<?php');
        file_put_contents($root . '/sub/b.php', '<?php');
        file_put_contents($root . '/c.txt', 'text');
        file_put_contents($root . '/vendor/d.php', '<?php');

        $watcher  = new FileWatcher(['php'], ['vendor']);
        $snapshot = $watcher->snapshot($root);
        $paths    = array_map(fn($p) => str_replace($root . '/', '', $p), array_keys($snapshot));
        sort($paths);

        $this->assertSame(['a.php', 'sub/b.php'], $paths);

        // cleanup
        @unlink($root . '/a.php');
        @unlink($root . '/sub/b.php');
        @unlink($root . '/c.txt');
        @unlink($root . '/vendor/d.php');
        @rmdir($root . '/sub');
        @rmdir($root . '/vendor');
        @rmdir($root);
    }
}
