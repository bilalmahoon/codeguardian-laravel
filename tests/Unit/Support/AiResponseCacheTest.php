<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\AiResponseCache;
use PHPUnit\Framework\TestCase;

class AiResponseCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cg_cache_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_key_is_deterministic_and_model_sensitive(): void
    {
        $a = AiResponseCache::key('claude', 'opus', 'sys', 'user', 8192);
        $b = AiResponseCache::key('claude', 'opus', 'sys', 'user', 8192);
        $c = AiResponseCache::key('claude', 'haiku', 'sys', 'user', 8192);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c, 'Different model must yield a different key');
    }

    public function test_put_then_get_round_trip(): void
    {
        $cache = new AiResponseCache($this->dir, 0, true);
        $key   = AiResponseCache::key('openai', 'gpt-4o', 's', 'u', null);

        $this->assertNull($cache->get($key));
        $cache->put($key, '{"ok":true}');
        $this->assertSame('{"ok":true}', $cache->get($key));
    }

    public function test_disabled_cache_never_returns_or_stores(): void
    {
        $cache = new AiResponseCache($this->dir, 0, false);
        $key   = AiResponseCache::key('openai', 'gpt-4o', 's', 'u', null);

        $cache->put($key, 'value');
        $this->assertNull($cache->get($key));
    }

    public function test_ttl_expires_entries(): void
    {
        $cache = new AiResponseCache($this->dir, 1, true);
        $key   = AiResponseCache::key('openai', 'gpt-4o', 's', 'u', null);
        $cache->put($key, 'value');

        // Backdate the file's created timestamp by rewriting it.
        $file = $this->dir . '/' . $key . '.json';
        file_put_contents($file, json_encode(['created' => time() - 10, 'value' => 'value']));

        $this->assertNull($cache->get($key), 'Entry older than TTL must be a miss');
    }

    public function test_clear_removes_all_entries(): void
    {
        $cache = new AiResponseCache($this->dir, 0, true);
        $cache->put(AiResponseCache::key('p', 'm', 's', 'u1', null), 'a');
        $cache->put(AiResponseCache::key('p', 'm', 's', 'u2', null), 'b');

        $this->assertSame(2, $cache->clear());
        $this->assertSame(0, $cache->clear());
    }
}
