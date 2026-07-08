<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Remembers which Sentry issues CodeGuardian has already handled, so the watch
 * loop (continuous observe + auto-fix) acts on each issue exactly once instead
 * of re-fixing the same error on every poll.
 *
 * A tiny append-style JSON map { issueId: {status, at} } on disk — no database.
 */
final class SentryStateStore
{
    /** @var array<string,array{status:string,at:string}> */
    private array $state = [];
    private bool $loaded = false;

    public function __construct(private readonly string $path)
    {
    }

    public static function fromConfig(): self
    {
        return new self(storage_path('codeguardian/sentry/state.json'));
    }

    public function isProcessed(string $issueId): bool
    {
        $this->load();
        return isset($this->state[$issueId]);
    }

    public function statusOf(string $issueId): ?string
    {
        $this->load();
        return $this->state[$issueId]['status'] ?? null;
    }

    /** Record that an issue has been handled with the given outcome. */
    public function markProcessed(string $issueId, string $status): void
    {
        $this->load();
        $this->state[$issueId] = ['status' => $status, 'at' => date('c')];
        $this->save();
    }

    /** Forget an issue so it will be re-processed next poll. */
    public function forget(string $issueId): void
    {
        $this->load();
        unset($this->state[$issueId]);
        $this->save();
    }

    /** @return array<string,array{status:string,at:string}> */
    public function all(): array
    {
        $this->load();
        return $this->state;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        if (is_file($this->path)) {
            $decoded = json_decode((string) @file_get_contents($this->path), true);
            if (is_array($decoded)) {
                // Keep only well-formed entries.
                foreach ($decoded as $id => $row) {
                    if (is_array($row) && isset($row['status'])) {
                        $this->state[(string) $id] = [
                            'status' => (string) $row['status'],
                            'at'     => (string) ($row['at'] ?? ''),
                        ];
                    }
                }
            }
        }
    }

    private function save(): void
    {
        @mkdir(dirname($this->path), 0775, true);
        @file_put_contents(
            $this->path,
            (string) json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
