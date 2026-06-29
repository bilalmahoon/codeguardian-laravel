<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Support\HistoryStore;
use Illuminate\Console\Command;

/**
 * `codeguardian:trend` — show how code health has moved across past analyze runs.
 *
 * Reads the append-only history written by `codeguardian:analyze` and renders a
 * compact table plus an overall direction (improving / declining) and a tiny
 * score sparkline.
 */
class TrendCommand extends Command
{
    protected $signature = 'codeguardian:trend
                            {--limit=20 : Number of recent runs to show}
                            {--json     : Output machine-readable JSON}';

    protected $description = 'Show code-health trends across past analysis runs';

    private const SPARK = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    public function handle(): int
    {
        $store   = HistoryStore::fromConfig();
        $limit   = max(1, (int) $this->option('limit'));
        $records = $store->recent($limit);

        if ($this->option('json')) {
            $this->line((string) json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($records === []) {
            $this->warn('No analysis history yet.');
            $this->line('  Run `php artisan codeguardian:analyze` to start tracking trends.');
            $this->line("  (history file: {$store->path()})");
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('  CodeGuardian AI — Health Trend');
        $this->newLine();

        $this->table(
            ['Date', 'Score', 'Grade', 'Risk', 'Total', 'Crit', 'High', 'Med', 'Low'],
            array_map(fn($r) => [
                $this->shortDate((string) ($r['at'] ?? '')),
                $this->num($r['score'] ?? null),
                (string) ($r['grade'] ?? '-'),
                $this->num($r['risk'] ?? null),
                (string) ($r['total'] ?? 0),
                (string) ($r['critical'] ?? 0),
                (string) ($r['high'] ?? 0),
                (string) ($r['medium'] ?? 0),
                (string) ($r['low'] ?? 0),
            ], $records)
        );

        $this->renderDirection($records);
        $this->newLine();

        return self::SUCCESS;
    }

    /** @param array<int,array<string,mixed>> $records */
    private function renderDirection(array $records): void
    {
        $scores = array_values(array_filter(
            array_map(fn($r) => is_numeric($r['score'] ?? null) ? (float) $r['score'] : null, $records),
            fn($v) => $v !== null
        ));

        if (count($scores) < 2) {
            return;
        }

        $first = $scores[0];
        $last  = end($scores);
        $delta = $last - $first;

        $this->line('  Score: ' . $this->sparkline($scores));

        if ($delta > 0.5) {
            $this->info(sprintf('  ▲ Improving (+%.1f since first run)', $delta));
        } elseif ($delta < -0.5) {
            $this->error(sprintf('  ▼ Declining (%.1f since first run)', $delta));
        } else {
            $this->line('  ◆ Stable');
        }

        $totals = array_map(fn($r) => (int) ($r['total'] ?? 0), $records);
        $this->line(sprintf('  Findings: %d → %d', $totals[0], end($totals)));
    }

    /** @param array<int,float> $values */
    private function sparkline(array $values): string
    {
        $min = min($values);
        $max = max($values);
        $span = $max - $min;

        $out = '';
        foreach ($values as $v) {
            $idx = $span > 0 ? (int) round(($v - $min) / $span * (count(self::SPARK) - 1)) : count(self::SPARK) - 1;
            $out .= self::SPARK[$idx];
        }
        return $out;
    }

    private function num(mixed $v): string
    {
        return is_numeric($v) ? (string) (int) $v : '-';
    }

    private function shortDate(string $iso): string
    {
        if ($iso === '') {
            return '-';
        }
        $ts = strtotime($iso);
        return $ts ? date('Y-m-d H:i', $ts) : $iso;
    }
}
