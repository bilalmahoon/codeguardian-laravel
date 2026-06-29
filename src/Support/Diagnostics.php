<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Environment & configuration diagnostics for `codeguardian:doctor`.
 *
 * The evaluation is a PURE function of an environment snapshot, so it can be
 * unit-tested exhaustively without touching the real system. The command layer
 * is responsible only for gathering the snapshot and rendering the result.
 *
 * Each check is: ['id', 'label', 'status' (pass|warn|fail), 'message', 'fix'].
 */
final class Diagnostics
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** Minimum supported PHP version. */
    public const MIN_PHP = '8.1.0';

    /** PHP extensions the engine relies on. */
    public const REQUIRED_EXTENSIONS = ['json', 'tokenizer', 'mbstring'];

    /**
     * @param array<string,mixed> $env
     * @return array<int,array<string,string>>
     */
    public static function evaluate(array $env): array
    {
        $checks = [];

        $checks[] = self::checkPhp((string) ($env['php_version'] ?? PHP_VERSION));

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = self::checkExtension($ext, (array) ($env['extensions'] ?? []));
        }

        $checks[] = self::checkAi(
            (string) ($env['mode'] ?? 'static'),
            (string) ($env['provider'] ?? 'openai'),
            (bool) ($env['has_api_key'] ?? false)
        );

        foreach ((array) ($env['writable'] ?? []) as $label => $info) {
            $checks[] = self::checkWritable((string) $label, (array) $info);
        }

        $checks[] = self::checkPhpUnit((bool) ($env['phpunit_available'] ?? false));
        $checks[] = self::checkConfigPublished((bool) ($env['config_published'] ?? false));
        $checks[] = self::checkDashboard(
            (bool) ($env['dashboard_enabled'] ?? false),
            (bool) ($env['dashboard_local_only'] ?? true),
            (string) ($env['app_env'] ?? 'production'),
            (array) ($env['dashboard_middleware'] ?? ['web'])
        );

        if (array_key_exists('modules_detected', $env)) {
            $checks[] = self::checkModules($env['modules_detected']);
        }

        return $checks;
    }

    /**
     * Tally checks by status.
     *
     * @param array<int,array<string,string>> $checks
     * @return array{pass:int,warn:int,fail:int}
     */
    public static function summarize(array $checks): array
    {
        $counts = [self::PASS => 0, self::WARN => 0, self::FAIL => 0];
        foreach ($checks as $c) {
            $status = $c['status'] ?? self::PASS;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        return $counts;
    }

    // ─── Individual checks ──────────────────────────────────────────────────

    private static function checkPhp(string $version): array
    {
        $ok = version_compare($version, self::MIN_PHP, '>=');
        return [
            'id'      => 'php_version',
            'label'   => 'PHP version',
            'status'  => $ok ? self::PASS : self::FAIL,
            'message' => $ok
                ? "PHP {$version} (>= " . self::MIN_PHP . ')'
                : "PHP {$version} is below the required " . self::MIN_PHP,
            'fix'     => $ok ? '' : 'Upgrade to PHP 8.1 or newer.',
        ];
    }

    /** @param array<int,string> $loaded */
    private static function checkExtension(string $ext, array $loaded): array
    {
        $loaded = array_map('strtolower', $loaded);
        $ok     = in_array(strtolower($ext), $loaded, true);
        return [
            'id'      => 'ext_' . $ext,
            'label'   => "Extension: {$ext}",
            'status'  => $ok ? self::PASS : self::FAIL,
            'message' => $ok ? "{$ext} is loaded" : "{$ext} is NOT loaded",
            'fix'     => $ok ? '' : "Install/enable the PHP {$ext} extension (e.g. php8.1-{$ext}).",
        ];
    }

    private static function checkAi(string $mode, string $provider, bool $hasKey): array
    {
        if (! in_array($mode, ['ai', 'hybrid'], true)) {
            return [
                'id'      => 'ai_config',
                'label'   => 'AI provider',
                'status'  => self::PASS,
                'message' => "Static mode — running offline (no API key needed)",
                'fix'     => 'Tip: set CODEGUARDIAN_MODE=hybrid + an API key for AI-powered deep review.',
            ];
        }

        if ($hasKey) {
            return [
                'id'      => 'ai_config',
                'label'   => 'AI provider',
                'status'  => self::PASS,
                'message' => "Mode '{$mode}' with provider '{$provider}' — API key detected",
                'fix'     => '',
            ];
        }

        $envVar = strtoupper($provider);
        return [
            'id'      => 'ai_config',
            'label'   => 'AI provider',
            'status'  => self::FAIL,
            'message' => "Mode '{$mode}' requires an API key for '{$provider}', but none is set",
            'fix'     => "Set CODEGUARDIAN_{$envVar}_KEY in your .env, or switch CODEGUARDIAN_MODE=static.",
        ];
    }

    /** @param array{path?:string,writable?:bool} $info */
    private static function checkWritable(string $label, array $info): array
    {
        $writable = (bool) ($info['writable'] ?? false);
        $path     = (string) ($info['path'] ?? '');
        return [
            'id'      => 'writable_' . strtolower(str_replace(' ', '_', $label)),
            'label'   => "Writable: {$label}",
            'status'  => $writable ? self::PASS : self::FAIL,
            'message' => $writable ? "{$path} is writable" : "{$path} is NOT writable",
            'fix'     => $writable ? '' : "Grant write permission: chmod -R ug+w \"{$path}\".",
        ];
    }

    private static function checkPhpUnit(bool $available): array
    {
        return [
            'id'      => 'phpunit',
            'label'   => 'Test runner',
            'status'  => $available ? self::PASS : self::WARN,
            'message' => $available
                ? 'PHPUnit/Pest is available'
                : 'No PHPUnit/Pest binary found',
            'fix'     => $available ? '' : 'Install PHPUnit (composer require --dev phpunit/phpunit) so refactor can verify changes.',
        ];
    }

    private static function checkConfigPublished(bool $published): array
    {
        return [
            'id'      => 'config',
            'label'   => 'Config file',
            'status'  => $published ? self::PASS : self::WARN,
            'message' => $published
                ? 'config/codeguardian.php is published'
                : 'Using bundled default config (not published)',
            'fix'     => $published ? '' : 'Optional: php artisan vendor:publish --tag=codeguardian-config to customise.',
        ];
    }

    /** @param array<int,string> $middleware */
    private static function checkDashboard(bool $enabled, bool $localOnly, string $appEnv, array $middleware): array
    {
        if (! $enabled) {
            return [
                'id'      => 'dashboard',
                'label'   => 'Web dashboard',
                'status'  => self::PASS,
                'message' => 'Dashboard disabled',
                'fix'     => '',
            ];
        }

        $exposed = $appEnv === 'production' && ! $localOnly;
        if ($exposed) {
            return [
                'id'      => 'dashboard',
                'label'   => 'Web dashboard',
                'status'  => self::WARN,
                'message' => 'Dashboard is enabled in production WITHOUT local-only restriction',
                'fix'     => 'Set codeguardian.dashboard.restrict_to_local=true or add auth middleware before exposing it.',
            ];
        }

        return [
            'id'      => 'dashboard',
            'label'   => 'Web dashboard',
            'status'  => self::PASS,
            'message' => 'Dashboard enabled and access-restricted',
            'fix'     => '',
        ];
    }

    private static function checkModules(mixed $modules): array
    {
        $isModular = $modules === true || (is_string($modules) && $modules !== '' && $modules !== 'none');
        return [
            'id'      => 'modules',
            'label'   => 'Project structure',
            'status'  => self::PASS,
            'message' => $isModular
                ? 'Modular project detected — module-scoped analysis available'
                : 'Standard Laravel structure',
            'fix'     => '',
        ];
    }
}
