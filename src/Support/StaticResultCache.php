<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Caches a whole static-analysis result keyed by the content of the scanned
 * files plus the enabled analyzers. Because the key is content-addressed, a
 * cache hit is always correct: if any file changes, the hash changes and the
 * analysis re-runs. Re-running CI on an unchanged tree returns instantly.
 *
 * The cache stores the normalized result array as JSON. Pure key derivation +
 * simple disk IO make it easy to test.
 */
final class StaticResultCache
{
    /** Bump when the engine's output shape or rules change materially. */
    private const SCHEMA = 'v1';

    public function __construct(
        private string $dir,
        private bool $enabled = true,
    ) {}

    public static function fromConfig(): self
    {
        try {
            $enabled = (bool) config('codeguardian.cache.static_enabled', false);
            $dir     = (string) config(
                'codeguardian.cache.static_dir',
                storage_path('codeguardian/cache/static')
            );
            return new self($dir, $enabled);
        } catch (\Throwable) {
            return new self(sys_get_temp_dir() . '/codeguardian-static-cache', false);
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Content-addressed key for a scan.
     *
     * @param array<string,string> $files   [path => content]
     * @param array<string,bool>   $options enabled analyzers
     */
    public static function key(array $files, array $options): string
    {
        $fileHashes = [];
        foreach ($files as $path => $content) {
            $fileHashes[(string) $path] = md5((string) $content);
        }
        ksort($fileHashes);

        $enabled = [];
        foreach ($options as $name => $on) {
            if ($on === true) {
                $enabled[] = $name;
            }
        }
        sort($enabled);

        return hash('sha256', self::SCHEMA . '|' . json_encode([
            'files'   => $fileHashes,
            'enabled' => $enabled,
        ]));
    }

    /** @return array<string,mixed>|null */
    public function get(string $key): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $file = $this->pathFor($key);
        if (! is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $result */
    public function put(string $key, array $result): void
    {
        if (! $this->enabled) {
            return;
        }

        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            return;
        }

        @file_put_contents(
            $this->pathFor($key),
            json_encode($result, JSON_UNESCAPED_SLASHES)
        );
    }

    public function clear(): int
    {
        if (! is_dir($this->dir)) {
            return 0;
        }
        $n = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if (@unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    private function pathFor(string $key): string
    {
        return rtrim($this->dir, '/\\') . '/' . $key . '.json';
    }
}
