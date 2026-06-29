<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Support\RuleRegistry;
use Illuminate\Console\Command;

/**
 * `codeguardian:rules` — list every detection rule and its effective state,
 * merging the built-in catalog with your config('codeguardian.rules') overrides.
 *
 * Improves discoverability: developers can see exactly what the engine checks,
 * which rules they've disabled, and any severity overrides — at a glance.
 */
class RulesCommand extends Command
{
    protected $signature = 'codeguardian:rules
                            {--group=        : Filter by analyzer group: architecture|security|performance|tech_debt}
                            {--enabled-only  : Show only enabled rules}
                            {--json          : Output machine-readable JSON}';

    protected $description = 'List all detection rules and their effective configuration';

    public function handle(): int
    {
        $spec = RuleRegistry::fromConfig((array) config('codeguardian.rules', []));
        $rows = RuleRegistry::describe($spec);

        $group = strtolower((string) ($this->option('group') ?: ''));
        if ($group !== '') {
            $rows = array_values(array_filter($rows, fn($r) => $r['group'] === $group));
        }
        if ($this->option('enabled-only')) {
            $rows = array_values(array_filter($rows, fn($r) => $r['enabled']));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->warn('No rules match the given filters.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('  CodeGuardian AI — Detection Rules');
        $this->line('  Override any rule in config/codeguardian.php → \'rules\'.');
        $this->newLine();

        $this->table(
            ['Rule', 'Group', 'Status', 'Severity'],
            array_map(fn($r) => [
                $r['id'],
                $r['group'],
                $r['enabled'] ? 'enabled' : 'DISABLED',
                $r['severity'],
            ], $rows)
        );

        $enabled  = count(array_filter($rows, fn($r) => $r['enabled']));
        $disabled = count($rows) - $enabled;
        $this->line("  {$enabled} enabled · {$disabled} disabled · " . count($rows) . ' total');
        $this->newLine();

        return self::SUCCESS;
    }
}
