<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Console;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Premium, live multi-stage CLI renderer.
 *
 * In a TTY it draws a single continuously-updating block (pipeline stages with
 * spinners/timers, an overall progress bar, ETA, the file currently being
 * processed, and live counters). When the output is NOT decorated (CI, piped,
 * tests) it degrades gracefully to clean one-line-per-transition logging, so
 * nothing depends on ANSI being available.
 *
 * The rendering math lives in ProgressFormat and the state in Pipeline — this
 * class is the thin presentation layer that ties them to a Symfony output.
 */
final class ConsoleReporter
{
    private const SPINNER = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    private const THROTTLE_MS = 80;

    private Pipeline $pipeline;
    private bool $decorated;
    private int $linesDrawn = 0;
    private int $spinnerIdx = 0;
    private float $lastRenderAt = 0.0;
    private string $title;

    /**
     * @param array<int,array{key:string,label:string}> $stages
     */
    public function __construct(
        private readonly OutputInterface $output,
        array $stages,
        string $title = 'CodeGuardian',
        ?bool $forceDecorated = null,
    ) {
        $this->pipeline  = new Pipeline($stages);
        $this->decorated = $forceDecorated ?? $output->isDecorated();
        $this->title     = $title;
    }

    public function pipeline(): Pipeline
    {
        return $this->pipeline;
    }

    // ─── Stage transitions ─────────────────────────────────────────────────────

    public function start(string $key, int $totalUnits = 0): void
    {
        $this->pipeline->start($key, $totalUnits);
        if (! $this->decorated) {
            $this->output->writeln('  <fg=cyan>▸</> ' . $this->labelOf($key) . ' <fg=gray>…</>');
            return;
        }
        $this->render(true);
    }

    public function advance(string $key, ?string $file = null, int $by = 1): void
    {
        $this->pipeline->advance($key, $by);
        if ($file !== null) {
            $this->pipeline->setCurrentFile($file);
        }
        $this->pipeline->incr('files', $by);
        if ($this->decorated) {
            $this->render(false);
        }
    }

    public function finish(string $key, string $note = ''): void
    {
        $this->pipeline->finish($key, $note);
        $this->pipeline->setCurrentFile(null);
        if (! $this->decorated) {
            $suffix = $note !== '' ? " <fg=gray>({$note})</>" : '';
            $this->output->writeln('  <fg=green>✓</> ' . $this->labelOf($key) . $suffix);
            return;
        }
        $this->render(true);
    }

    public function fail(string $key, string $note = ''): void
    {
        $this->pipeline->fail($key, $note);
        if (! $this->decorated) {
            $this->output->writeln('  <fg=red>✗</> ' . $this->labelOf($key) . ($note !== '' ? " <fg=gray>({$note})</>" : ''));
            return;
        }
        $this->render(true);
    }

    public function skip(string $key, string $note = ''): void
    {
        $this->pipeline->skip($key, $note);
        if (! $this->decorated) {
            $this->output->writeln('  <fg=gray>⊝ ' . $this->labelOf($key) . ($note !== '' ? " ({$note})" : '') . '</>');
            return;
        }
        $this->render(true);
    }

    public function count(string $name, int $by = 1): void
    {
        $this->pipeline->incr($name, $by);
    }

    public function setCount(string $name, int $value): void
    {
        $this->pipeline->setCounter($name, $value);
    }

    /** Finalise the live block so subsequent output starts on a clean line. */
    public function done(): void
    {
        if ($this->decorated) {
            $this->render(true);
            $this->output->writeln('');
        }
    }

    /**
     * Print a final "execution stats" card: per-stage time breakdown + totals.
     * Always printed (decoration-independent). Complements the score summary.
     */
    public function executionStats(): void
    {
        $o = $this->output;
        $o->writeln('  <fg=gray>┌─ Execution stats ───────────────────────────────┐</>');

        $total = max($this->pipeline->elapsed(), 0.0001);
        foreach ($this->pipeline->stages() as $s) {
            if (! in_array($s['status'], [Pipeline::DONE, Pipeline::FAILED, Pipeline::SKIPPED], true)) {
                continue;
            }
            [$icon, $color] = match ($s['status']) {
                Pipeline::DONE    => ['✓', 'green'],
                Pipeline::FAILED  => ['✗', 'red'],
                default           => ['⊝', 'gray'],
            };
            $elapsed = (float) $s['elapsed'];
            $share   = ProgressFormat::percent((int) round($elapsed * 1000), (int) round($total * 1000));
            $o->writeln(sprintf(
                '  <fg=gray>│</> <fg=%s>%s</> %s <fg=gray>%s</> <fg=gray>%s · %d%%</>',
                $color,
                $icon,
                str_pad($s['label'], 22),
                ProgressFormat::bar($s['status'] === Pipeline::SKIPPED ? 0 : $share, 10),
                str_pad(ProgressFormat::duration($elapsed), 7, ' ', STR_PAD_LEFT),
                $share
            ));
        }

        $c     = $this->pipeline->counters();
        $files = $c['files_total'] ?? ($c['files'] ?? 0);
        $o->writeln('  <fg=gray>├─────────────────────────────────────────────────┤</>');
        $o->writeln(sprintf(
            '  <fg=gray>│</> <options=bold>Total %s</> <fg=gray>·</> %d files <fg=gray>·</> %s <fg=gray>·</> %d rules',
            ProgressFormat::duration($this->pipeline->elapsed()),
            $files,
            ProgressFormat::rate($this->pipeline->elapsed(), $files) . ' throughput',
            $c['rules'] ?? 0
        ));
        $o->writeln('  <fg=gray>└─────────────────────────────────────────────────┘</>');
    }

    // ─── Rendering ──────────────────────────────────────────────────────────────

    public function render(bool $force): void
    {
        if (! $this->decorated) {
            return;
        }

        $now = microtime(true) * 1000;
        if (! $force && ($now - $this->lastRenderAt) < self::THROTTLE_MS) {
            return;
        }
        $this->lastRenderAt = $now;
        $this->spinnerIdx   = ($this->spinnerIdx + 1) % count(self::SPINNER);

        $lines = $this->buildFrame();

        // Move cursor up over the previously-drawn block and repaint each line.
        if ($this->linesDrawn > 0) {
            $this->output->write("\x1b[{$this->linesDrawn}A");
        }
        foreach ($lines as $line) {
            $this->output->write("\x1b[2K"); // clear entire line
            $this->output->writeln($line);
        }
        $this->linesDrawn = count($lines);
    }

    /** @return array<int,string> */
    private function buildFrame(): array
    {
        $pct     = $this->pipeline->percent();
        $elapsed = $this->pipeline->elapsed();
        $eta     = ($pct > 0 && $pct < 100)
            ? ProgressFormat::duration($elapsed / ($pct / 100) * (1 - $pct / 100))
            : '—';

        $lines = [];
        $lines[] = sprintf(
            '  <options=bold>%s</> <fg=gray>·</> %s  <fg=cyan>%s</> <options=bold>%d%%</>   <fg=gray>elapsed %s · ETA %s</>',
            $this->title,
            $this->headline(),
            ProgressFormat::bar($pct, 22),
            $pct,
            ProgressFormat::duration($elapsed),
            $eta
        );

        $stages = $this->pipeline->stages();
        $last   = count($stages) - 1;
        foreach ($stages as $i => $s) {
            $branch = $i === $last ? '└─' : '├─';
            $lines[] = '  <fg=gray>' . $branch . '</> ' . $this->stageLine($s);
        }

        $lines[] = '     ' . $this->counterLine();

        return $lines;
    }

    private function headline(): string
    {
        $cur = $this->pipeline->currentStage();
        return $cur ? $cur['label'] : ($this->pipeline->isComplete() ? 'Complete' : 'Starting');
    }

    private function stageLine(array $s): string
    {
        [$icon, $color] = match ($s['status']) {
            Pipeline::DONE    => ['✓', 'green'],
            Pipeline::FAILED  => ['✗', 'red'],
            Pipeline::SKIPPED => ['⊝', 'gray'],
            Pipeline::RUNNING => [self::SPINNER[$this->spinnerIdx], 'cyan'],
            default           => ['○', 'gray'],
        };

        $label = str_pad($s['label'], 22);
        $line  = "<fg={$color}>{$icon}</> {$label}";

        if ($s['status'] === Pipeline::RUNNING) {
            $detail = '';
            if ($s['total_units'] > 0) {
                $detail = $s['done_units'] . '/' . $s['total_units'];
            }
            $file = $this->pipeline->currentFile();
            if ($file !== null) {
                $detail = trim($detail . ' ' . ProgressFormat::shortenPath(basename($file), 34));
            }
            if ($detail !== '') {
                $line .= '<fg=gray>  ' . $detail . '</>';
            }
        } elseif (in_array($s['status'], [Pipeline::DONE, Pipeline::FAILED], true)) {
            $bits = [];
            if ($s['note'] !== '') {
                $bits[] = $s['note'];
            }
            if ($s['elapsed'] > 0) {
                $bits[] = ProgressFormat::duration($s['elapsed']);
            }
            if (! empty($bits)) {
                $line .= '<fg=gray>  ' . implode(' · ', $bits) . '</>';
            }
        } elseif ($s['status'] === Pipeline::SKIPPED && $s['note'] !== '') {
            $line .= '<fg=gray>  ' . $s['note'] . '</>';
        }

        return $line;
    }

    private function counterLine(): string
    {
        $p     = $this->pipeline;
        $parts = [];

        $filesTot = $p->counter('files_total');
        if ($filesTot > 0) {
            $parts[] = "<fg=gray>files</> {$filesTot}";
        }
        if ($p->counter('rules') > 0) {
            $parts[] = '<fg=gray>rule groups</> ' . $p->counter('rules');
        }
        if ($p->counter('critical') > 0) {
            $parts[] = '<fg=red>🔴 ' . $p->counter('critical') . '</>';
        }
        if ($p->counter('high') > 0) {
            $parts[] = '<fg=yellow>🟠 ' . $p->counter('high') . '</>';
        }
        if ($p->counter('medium') > 0) {
            $parts[] = '🟡 ' . $p->counter('medium');
        }
        if ($p->counter('low') > 0) {
            $parts[] = '🟢 ' . $p->counter('low');
        }
        if ($p->counter('suggestions') > 0) {
            $parts[] = '<fg=gray>suggestions</> ' . $p->counter('suggestions');
        }
        if ($p->counter('refactors') > 0) {
            $parts[] = '<fg=green>refactors</> ' . $p->counter('refactors');
        }

        return empty($parts) ? '<fg=gray>warming up…</>' : implode('  <fg=gray>·</>  ', $parts);
    }

    private function labelOf(string $key): string
    {
        foreach ($this->pipeline->stages() as $s) {
            if ($s['key'] === $key) {
                return $s['label'];
            }
        }
        return $key;
    }
}
