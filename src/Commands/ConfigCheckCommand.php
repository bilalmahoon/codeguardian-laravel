<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Support\ConfigValidator;
use Illuminate\Console\Command;

/**
 * Validates the active codeguardian configuration and reports mistakes that
 * would otherwise fail silently (wrong mode/provider, malformed custom rules,
 * invalid gate keys, …). Returns non-zero on hard errors so CI can gate on it.
 */
class ConfigCheckCommand extends Command
{
    protected $signature = 'codeguardian:config-check {--json : Output the result as JSON}';

    protected $description = 'Validate your codeguardian.php configuration';

    public function handle(): int
    {
        $config = (array) config('codeguardian', []);
        $result = ConfigValidator::validate($config);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('🔧 CodeGuardian config check');
        $this->newLine();

        if ($result['errors'] === [] && $result['warnings'] === []) {
            $this->info('  ✅ Configuration is valid.');
            return self::SUCCESS;
        }

        foreach ($result['warnings'] as $w) {
            $this->warn('  ⚠ ' . $w);
        }
        foreach ($result['errors'] as $e) {
            $this->error('  ✗ ' . $e);
        }

        $this->newLine();
        if ($result['errors'] === []) {
            $this->info('  ✅ No hard errors (warnings only).');
            return self::SUCCESS;
        }

        $this->error('  ' . count($result['errors']) . ' configuration error(s) found.');
        return self::FAILURE;
    }
}
