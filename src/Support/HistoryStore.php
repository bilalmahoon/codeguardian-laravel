<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Append-only history of analysis runs, used to chart health/risk trends over
 * time (see `codeguardian:trend`). Stored as JSON-lines so appends are cheap
 * and the file stays append-safe across concurrent runs.
 *
 * The metric extraction (summaryFrom) is pure and unit-testable; file IO is a
 * thin wrapper around it.
 */
final class HistoryStore
{
    public function __construct(private readonly string $file)
    {
    }

    public static function fromConfig(): self
    {
        $path = (string) config(
            'codeguardian.output.history_file',
            storage_path('codeguardian/history.jsonl')
        );

        return new self($path);
    }

    /**
     * Extract a compact, comparable metric record from a full result set.
     *
     * @param array<string,mixed> $results
     * @param array<string,mixed> $meta    extra context (scope, mode, …)
     * @return array<string,mixed>
     */
    public static function summaryFrom(array $results, array $meta = []): array
    {
        $summary = $results['summary'] ?? [];

        $dimensions = [];
        foreach ($results['quality']['dimensions'] ?? [] as $key => $dim) {
            $id = is_string($key) ? $key : ($dim['key'] ?? $dim['label'] ?? '');
            if ($id !== '') {
                $dimensions[(string) $id] = (int) ($dim['score'] ?? 0);
            }
        }

        return array_merge([
            'at'         => gmdate('c'),
            'project'    => $results['project_name'] ?? 'project',
            'score'      => $results['overall_score'] ?? null,
            'grade'      => $results['grade'] ?? null,
            'risk'       => $summary['risk_score'] ?? null,
            'risk_level' => $summary['risk_level'] ?? null,
            'total'      => (int) ($summary['total_issues'] ?? 0),
            'critical'   => (int) ($summary['critical'] ?? 0),
            'high'       => (int) ($summary['high'] ?? 0),
            'medium'     => (int) ($summary['medium'] ?? 0),
            'low'        => (int) ($summary['low'] ?? 0),
            'dimensions' => $dimensions,
        ], $meta);
    }

    /**
     * Append a run's metrics to the history file.
     *
     * @param array<string,mixed> $results
     * @param array<string,mixed> $meta
     */
    public function record(array $results, array $meta = []): bool
    {
        $record = self::summaryFrom($results, $meta);

        $dir = dirname($this->file);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";

        return @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * Read the most recent records (oldest → newest).
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 20): array
    {
        if (! is_file($this->file)) {
            return [];
        }

        $lines = file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        if ($limit > 0 && count($lines) > $limit) {
            $lines = array_slice($lines, -$limit);
        }

        $records = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    public function path(): string
    {
        return $this->file;
    }
}
