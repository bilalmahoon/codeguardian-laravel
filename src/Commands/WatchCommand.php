<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Support\FileWatcher;
use Illuminate\Console\Command;

/**
 * Watches the project for file changes and re-runs a fast, scoped analysis on
 * each save — the inner-loop companion to the full CI scan.
 */
class WatchCommand extends Command
{
    protected $signature = 'codeguardian:watch
                            {--path=       : Directory to watch (default: app/)}
                            {--interval=2  : Seconds between polls}
                            {--ext=php     : Comma-separated file extensions to watch}
                            {--agents=     : Restrict analyzers (csv): architect,security,performance,tech_debt,database}
                            {--runs=0      : Stop after this many change-triggered runs (0 = run forever)}';

    protected $description = 'Watch files and re-analyze changed files on every save';

    public function handle(): int
    {
        $path = $this->option('path') ?: base_path('app');
        if (! is_dir($path)) {
            $this->error("Path does not exist: {$path}");
            return self::FAILURE;
        }

        $interval = max(1, (int) $this->option('interval'));
        $exts     = array_filter(array_map('trim', explode(',', (string) ($this->option('ext') ?: 'php'))));
        $maxRuns  = (int) $this->option('runs');

        $watcher  = new FileWatcher($exts);

        $this->info('👀 CodeGuardian watch');
        $this->line("   Watching: {$path}");
        $this->line('   Extensions: ' . implode(', ', $exts) . "   Poll: {$interval}s");
        $this->line('   Press Ctrl+C to stop.');
        $this->newLine();

        $previous = $watcher->snapshot($path);
        $runs     = 0;

        while (true) {
            sleep($interval);

            $current = $watcher->snapshot($path);
            $changed = FileWatcher::changed($previous, $current);
            $previous = $current;

            if ($changed === []) {
                continue;
            }

            $runs++;
            $this->newLine();
            $this->info('  ⚡ ' . count($changed) . ' file(s) changed — analyzing...');
            foreach (array_slice($changed, 0, 10) as $f) {
                $this->line('     • ' . $this->relative($f, $path));
            }

            $args = [
                '--path'   => $path,
                '--changed' => true,
                '--plain'  => true,
                '--no-report' => true,
                '--no-history' => true,
            ];
            if ($agents = $this->option('agents')) {
                $args['--agents'] = $agents;
            }

            $this->call('codeguardian:analyze', $args);

            if ($maxRuns > 0 && $runs >= $maxRuns) {
                $this->newLine();
                $this->info("  Reached --runs={$maxRuns}. Stopping.");
                return self::SUCCESS;
            }
        }
    }

    private function relative(string $file, string $root): string
    {
        $root = rtrim($root, '/\\') . '/';
        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }
}
