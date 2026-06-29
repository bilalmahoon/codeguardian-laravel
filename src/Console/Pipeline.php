<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Console;

/**
 * A pure, testable state machine describing a multi-stage execution pipeline.
 *
 * It tracks, for each stage: its status, timing, an optional note, and (for
 * file-processing stages) sub-progress. It also holds live counters (warnings,
 * errors, suggestions, refactors, …). The renderer reads this state to draw
 * frames; this class never touches the terminal, so it can be unit-tested.
 *
 * Status values: pending | running | done | failed | skipped.
 */
final class Pipeline
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const FAILED  = 'failed';
    public const SKIPPED = 'skipped';

    /** @var array<int,array<string,mixed>> */
    private array $stages = [];

    /** @var array<string,int> */
    private array $counters = [];

    private ?string $currentFile = null;
    private float $startedAt;
    /** Injected clock for deterministic tests. */
    private $clock;

    /**
     * @param array<int,array{key:string,label:string}> $stages
     * @param callable():float|null                      $clock
     */
    public function __construct(array $stages = [], ?callable $clock = null)
    {
        $this->clock     = $clock ?? static fn(): float => microtime(true);
        $this->startedAt = ($this->clock)();

        foreach ($stages as $stage) {
            $this->stages[] = [
                'key'        => $stage['key'],
                'label'      => $stage['label'],
                'status'     => self::PENDING,
                'started_at' => null,
                'elapsed'    => 0.0,
                'note'       => '',
                'done_units' => 0,
                'total_units'=> 0,
            ];
        }
    }

    public function addStage(string $key, string $label): void
    {
        $this->stages[] = [
            'key' => $key, 'label' => $label, 'status' => self::PENDING,
            'started_at' => null, 'elapsed' => 0.0, 'note' => '',
            'done_units' => 0, 'total_units' => 0,
        ];
    }

    public function start(string $key, int $totalUnits = 0): void
    {
        $i = $this->index($key);
        if ($i === null) {
            return;
        }
        $this->stages[$i]['status']      = self::RUNNING;
        $this->stages[$i]['started_at']  = ($this->clock)();
        $this->stages[$i]['total_units'] = $totalUnits;
        $this->stages[$i]['done_units']  = 0;
    }

    public function advance(string $key, int $by = 1): void
    {
        $i = $this->index($key);
        if ($i === null) {
            return;
        }
        $this->stages[$i]['done_units'] += $by;
    }

    public function finish(string $key, string $note = ''): void
    {
        $this->close($key, self::DONE, $note);
    }

    public function fail(string $key, string $note = ''): void
    {
        $this->close($key, self::FAILED, $note);
    }

    public function skip(string $key, string $note = ''): void
    {
        $i = $this->index($key);
        if ($i === null) {
            return;
        }
        $this->stages[$i]['status'] = self::SKIPPED;
        $this->stages[$i]['note']   = $note;
    }

    private function close(string $key, string $status, string $note): void
    {
        $i = $this->index($key);
        if ($i === null) {
            return;
        }
        $started = $this->stages[$i]['started_at'] ?? ($this->clock)();
        $this->stages[$i]['status']  = $status;
        $this->stages[$i]['elapsed'] = ($this->clock)() - $started;
        if ($note !== '') {
            $this->stages[$i]['note'] = $note;
        }
        if ($this->stages[$i]['total_units'] > 0) {
            $this->stages[$i]['done_units'] = $this->stages[$i]['total_units'];
        }
    }

    // ─── Counters ─────────────────────────────────────────────────────────────

    public function incr(string $counter, int $by = 1): void
    {
        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + $by;
    }

    public function setCounter(string $counter, int $value): void
    {
        $this->counters[$counter] = $value;
    }

    public function counter(string $counter): int
    {
        return $this->counters[$counter] ?? 0;
    }

    /** @return array<string,int> */
    public function counters(): array
    {
        return $this->counters;
    }

    public function setCurrentFile(?string $file): void
    {
        $this->currentFile = $file;
    }

    public function currentFile(): ?string
    {
        return $this->currentFile;
    }

    // ─── Read model ─────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function stages(): array
    {
        return $this->stages;
    }

    public function currentStage(): ?array
    {
        foreach ($this->stages as $s) {
            if ($s['status'] === self::RUNNING) {
                return $s;
            }
        }
        return null;
    }

    public function elapsed(): float
    {
        return ($this->clock)() - $this->startedAt;
    }

    /** Overall completion 0..100, weighting each stage equally with sub-progress. */
    public function percent(): int
    {
        $total = count($this->stages);
        if ($total === 0) {
            return 0;
        }

        $progress = 0.0;
        foreach ($this->stages as $s) {
            if (in_array($s['status'], [self::DONE, self::SKIPPED, self::FAILED], true)) {
                $progress += 1.0;
            } elseif ($s['status'] === self::RUNNING) {
                $progress += $s['total_units'] > 0
                    ? min(1.0, $s['done_units'] / $s['total_units'])
                    : 0.0;
            }
        }

        return (int) min(100, max(0, round($progress / $total * 100)));
    }

    public function isComplete(): bool
    {
        foreach ($this->stages as $s) {
            if (in_array($s['status'], [self::PENDING, self::RUNNING], true)) {
                return false;
            }
        }
        return true;
    }

    public function hasFailures(): bool
    {
        foreach ($this->stages as $s) {
            if ($s['status'] === self::FAILED) {
                return true;
            }
        }
        return false;
    }

    private function index(string $key): ?int
    {
        foreach ($this->stages as $i => $s) {
            if ($s['key'] === $key) {
                return $i;
            }
        }
        return null;
    }
}
