<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Content-addressed cache for AI responses.
 *
 * Re-running an analysis or refactor over unchanged code produces byte-identical
 * prompts. Without caching, every run re-pays the provider for the same answer.
 * This cache keys responses by a hash of (provider, model, system, user, maxTokens)
 * so identical requests are served from disk for $0.
 *
 * The cache is intentionally simple (one JSON file per entry) and fully
 * unit-testable: pass a directory to the constructor, or build one from config.
 */
final class AiResponseCache
{
    /**
     * @param string $dir     Absolute directory where cache entries live.
     * @param int    $ttl     Seconds an entry stays valid (0 = never expires).
     * @param bool   $enabled Master switch.
     */
    public function __construct(
        private string $dir,
        private int $ttl = 0,
        private bool $enabled = true,
    ) {}

    /**
     * Build from package config. Never throws — if the app container is not
     * available (pure unit tests) the cache is returned disabled.
     */
    public static function fromConfig(): self
    {
        try {
            $enabled = (bool) config('codeguardian.cache.ai_enabled', true);
            $ttl     = (int) config('codeguardian.cache.ttl', 0);
            $dir     = (string) config(
                'codeguardian.cache.dir',
                storage_path('codeguardian/cache/ai')
            );

            return new self($dir, $ttl, $enabled);
        } catch (\Throwable) {
            return new self(sys_get_temp_dir() . '/codeguardian-ai-cache', 0, false);
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** Deterministic cache key for a request. */
    public static function key(
        string $provider,
        string $model,
        string $system,
        string $user,
        ?int $maxTokens
    ): string {
        return hash('sha256', implode("\x00", [
            $provider,
            $model,
            (string) ($maxTokens ?? 0),
            $system,
            $user,
        ]));
    }

    /** Return the cached response for $key, or null on miss / expiry. */
    public function get(string $key): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $file = $this->pathFor($key);
        if (! is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($file), true);
        if (! is_array($decoded) || ! array_key_exists('value', $decoded)) {
            return null;
        }

        if ($this->ttl > 0) {
            $created = (int) ($decoded['created'] ?? 0);
            if ($created > 0 && (time() - $created) > $this->ttl) {
                @unlink($file);
                return null;
            }
        }

        return (string) $decoded['value'];
    }

    /** Store $value under $key. Silently no-ops when disabled or unwritable. */
    public function put(string $key, string $value): void
    {
        if (! $this->enabled) {
            return;
        }

        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            return;
        }

        @file_put_contents(
            $this->pathFor($key),
            json_encode(['created' => time(), 'value' => $value], JSON_UNESCAPED_SLASHES)
        );
    }

    /** Delete every cache entry. Returns the number of files removed. */
    public function clear(): int
    {
        if (! is_dir($this->dir)) {
            return 0;
        }

        $removed = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function pathFor(string $key): string
    {
        return rtrim($this->dir, '/\\') . '/' . $key . '.json';
    }
}
