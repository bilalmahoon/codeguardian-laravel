<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Validates a codeguardian config array and reports mistakes that would
 * otherwise fail silently or at runtime (wrong mode, unknown provider, malformed
 * custom rules, invalid gate keys, …). Pure: array in, {errors, warnings} out.
 */
final class ConfigValidator
{
    private const MODES     = ['static', 'ai', 'hybrid'];
    private const PROVIDERS = ['openai', 'claude', 'gemini'];
    private const PRESETS   = ['strict', 'balanced', 'lenient', ''];
    private const FORMATS   = ['json', 'html', 'md', 'sarif', 'junit', 'both', 'all'];
    private const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    /**
     * @param  array<string,mixed> $config
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public static function validate(array $config): array
    {
        $errors   = [];
        $warnings = [];

        // mode
        $mode = $config['mode'] ?? 'static';
        if (! in_array($mode, self::MODES, true)) {
            $errors[] = "mode '{$mode}' is invalid (expected: " . implode(', ', self::MODES) . ').';
        }

        // provider
        $provider = $config['provider'] ?? 'openai';
        if (! in_array($provider, self::PROVIDERS, true)) {
            $errors[] = "provider '{$provider}' is invalid (expected: " . implode(', ', self::PROVIDERS) . ').';
        }

        // AI mode without a key is a foot-gun.
        if (in_array($mode, ['ai', 'hybrid'], true)) {
            $key = $config[$provider]['key'] ?? null;
            if (empty($key)) {
                $warnings[] = "mode is '{$mode}' but no API key is set for provider '{$provider}'.";
            }
        }

        // preset
        $preset = (string) ($config['preset'] ?? '');
        if (! in_array($preset, self::PRESETS, true)) {
            $errors[] = "preset '{$preset}' is invalid (expected: strict, balanced, lenient).";
        }

        // output.format
        $format = $config['output']['format'] ?? 'both';
        if (! in_array($format, self::FORMATS, true)) {
            $errors[] = "output.format '{$format}' is invalid (expected: " . implode(', ', self::FORMATS) . ').';
        }

        // gates
        foreach ((array) ($config['gates'] ?? []) as $gate => $value) {
            if (! in_array($gate, QualityGate::KEYS, true)) {
                $errors[] = "gates.{$gate} is not a recognised budget key.";
            } elseif (! is_numeric($value)) {
                $errors[] = "gates.{$gate} must be a number, got " . gettype($value) . '.';
            }
        }

        // rules: severity overrides must be valid
        foreach ((array) ($config['rules'] ?? []) as $rule => $spec) {
            $sev = is_array($spec) ? ($spec['severity'] ?? null) : (is_string($spec) ? $spec : null);
            if (is_string($sev) && ! in_array($sev, self::SEVERITIES, true) && $sev !== '') {
                $errors[] = "rules.{$rule} severity '{$sev}' is invalid (expected critical|high|medium|low).";
            }
        }

        // custom_rules: shape + compilable regex
        foreach ((array) ($config['custom_rules'] ?? []) as $i => $rule) {
            if (! is_array($rule)) {
                $errors[] = "custom_rules[{$i}] must be an array.";
                continue;
            }
            foreach (['id', 'title', 'pattern'] as $required) {
                if (empty($rule[$required])) {
                    $errors[] = "custom_rules[{$i}] is missing required '{$required}'.";
                }
            }
            if (! empty($rule['pattern']) && @preg_match('/' . $rule['pattern'] . '/', '') === false) {
                $errors[] = "custom_rules[{$i}] pattern is not a valid regex.";
            }
            if (! empty($rule['severity']) && ! in_array($rule['severity'], self::SEVERITIES, true)) {
                $errors[] = "custom_rules[{$i}] severity '{$rule['severity']}' is invalid.";
            }
        }

        // analysis numeric sanity
        foreach (['max_file_size', 'max_files_per_scan', 'max_context_tokens', 'test_timeout'] as $numKey) {
            if (isset($config['analysis'][$numKey]) && ! is_numeric($config['analysis'][$numKey])) {
                $errors[] = "analysis.{$numKey} must be numeric.";
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
