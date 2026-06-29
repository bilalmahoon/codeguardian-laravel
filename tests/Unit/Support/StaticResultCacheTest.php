<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\StaticResultCache;
use PHPUnit\Framework\TestCase;

class StaticResultCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cg_scache_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_key_changes_when_file_content_changes(): void
    {
        $opts = ['security' => true];
        $k1 = StaticResultCache::key(['a.php' => '<?php echo 1;'], $opts);
        $k2 = StaticResultCache::key(['a.php' => '<?php echo 2;'], $opts);
        $k3 = StaticResultCache::key(['a.php' => '<?php echo 1;'], $opts);

        $this->assertNotSame($k1, $k2);
        $this->assertSame($k1, $k3);
    }

    public function test_key_changes_when_enabled_analyzers_change(): void
    {
        $files = ['a.php' => '<?php'];
        $k1 = StaticResultCache::key($files, ['security' => true]);
        $k2 = StaticResultCache::key($files, ['security' => true, 'performance' => true]);
        $this->assertNotSame($k1, $k2);
    }

    public function test_key_ignores_disabled_analyzers_and_order(): void
    {
        $files = ['a.php' => '<?php'];
        $k1 = StaticResultCache::key($files, ['security' => true, 'performance' => false]);
        $k2 = StaticResultCache::key($files, ['security' => true]);
        $this->assertSame($k1, $k2);
    }

    public function test_round_trip_when_enabled(): void
    {
        $cache = new StaticResultCache($this->dir, true);
        $key   = StaticResultCache::key(['a.php' => '<?php'], ['security' => true]);

        $this->assertNull($cache->get($key));
        $cache->put($key, ['overall_score' => 88, 'summary' => ['total_issues' => 3]]);

        $hit = $cache->get($key);
        $this->assertSame(88, $hit['overall_score']);
        $this->assertSame(3, $hit['summary']['total_issues']);
    }

    public function test_disabled_cache_is_inert(): void
    {
        $cache = new StaticResultCache($this->dir, false);
        $key   = StaticResultCache::key(['a.php' => '<?php'], []);
        $cache->put($key, ['x' => 1]);
        $this->assertNull($cache->get($key));
    }
}
