<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use CodeGuardian\Laravel\Analyzers\Severity;

/**
 * Config-driven rule control: enable/disable individual rules and override their
 * severity, without touching engine code.
 *
 * Config (config/codeguardian.php → 'rules'):
 *   'rules' => [
 *       'magic_numbers'  => false,          // disable a rule entirely
 *       'missing_types'  => 'low',          // downgrade severity
 *       'n_plus_one'     => 'critical',     // upgrade severity
 *       'todo_debt'      => ['enabled' => true, 'severity' => 'low'],
 *   ]
 *
 * Rules are identified by their finding "category". The full catalog below
 * powers the `codeguardian:rules` discovery command. Matching/transform logic
 * is pure and unit-testable.
 */
final class RuleRegistry
{
    /** Known rule ids → analyzer group. Keep in sync with the analyzers. */
    public const CATALOG = [
        // architecture
        'fat_model'              => 'architecture',
        'fat_controller'         => 'architecture',
        'service_layer'          => 'architecture',
        'dependency_injection'   => 'architecture',
        'config_misuse'          => 'architecture',
        'solid'                  => 'architecture',
        // security
        'sql_injection'          => 'security',
        'secret_exposure'        => 'security',
        'authorization'          => 'security',
        'mass_assignment'        => 'security',
        'xss'                    => 'security',
        'debug_code'             => 'security',
        'insecure_upload'        => 'security',
        'command_injection'      => 'security',
        'code_injection'         => 'security',
        'insecure_deserialization' => 'security',
        'weak_cryptography'      => 'security',
        'insecure_randomness'    => 'security',
        'path_traversal'         => 'security',
        'ssrf'                   => 'security',
        'open_redirect'          => 'security',
        'csrf'                   => 'security',
        'security_misconfiguration' => 'security',
        'file_inclusion'         => 'security',
        'tls_verification'       => 'security',
        // performance
        'n_plus_one'             => 'performance',
        'eager_loading'          => 'performance',
        'select_all'             => 'performance',
        'missing_cache'          => 'performance',
        'inefficient_count'      => 'performance',
        'missing_index'          => 'performance',
        'memory_usage'           => 'performance',
        'query_in_loop'          => 'performance',
        'over_fetching'          => 'performance',
        'nested_loops'           => 'performance',
        // tech debt
        'large_class'            => 'tech_debt',
        'high_complexity'        => 'tech_debt',
        'duplication'            => 'tech_debt',
        'todo_debt'              => 'tech_debt',
        'dead_code'              => 'tech_debt',
        'missing_types'          => 'tech_debt',
        'magic_numbers'          => 'tech_debt',
        'deep_nesting'           => 'tech_debt',
        'long_parameter_list'    => 'tech_debt',
        'boolean_flag'           => 'tech_debt',
        'swallowed_exception'    => 'tech_debt',
        'god_class'              => 'tech_debt',
    ];

    private const OFF_TOKENS = ['off', 'false', 'disabled', 'no', '0'];

    /**
     * Normalise raw config into a per-rule spec.
     *
     * @param array<string,mixed> $config
     * @return array<string,array{enabled:bool,severity:?string}>
     */
    public static function fromConfig(array $config): array
    {
        $spec = [];

        foreach ($config as $rule => $value) {
            $rule = strtolower(trim((string) $rule));
            if ($rule === '') {
                continue;
            }

            if (is_bool($value)) {
                $spec[$rule] = ['enabled' => $value, 'severity' => null];
                continue;
            }

            if (is_string($value)) {
                $v = strtolower(trim($value));
                if (in_array($v, self::OFF_TOKENS, true)) {
                    $spec[$rule] = ['enabled' => false, 'severity' => null];
                } elseif (in_array($v, ['critical', 'high', 'medium', 'low'], true)) {
                    $spec[$rule] = ['enabled' => true, 'severity' => $v];
                } else {
                    $spec[$rule] = ['enabled' => true, 'severity' => null];
                }
                continue;
            }

            if (is_array($value)) {
                $sev = isset($value['severity']) ? strtolower((string) $value['severity']) : null;
                $spec[$rule] = [
                    'enabled'  => array_key_exists('enabled', $value) ? (bool) $value['enabled'] : true,
                    'severity' => in_array($sev, ['critical', 'high', 'medium', 'low'], true) ? $sev : null,
                ];
            }
        }

        return $spec;
    }

    /**
     * Apply the rule spec to a full analyze() result: drop disabled rules,
     * remap severities, recompute summary.
     *
     * @param array<string,mixed> $results
     * @param array<string,array{enabled:bool,severity:?string}> $spec
     * @return array{0:array<string,mixed>,1:int,2:int}  [results, disabled, remapped]
     */
    public static function applyToResult(array $results, array $spec): array
    {
        if ($spec === []) {
            return [$results, 0, 0];
        }

        $disabled = 0;
        $remapped = 0;

        // $count is only true for the canonical all_findings pass so the agent
        // copies (the same findings, grouped) don't double-count the totals.
        $transform = function (array $list, bool $count) use ($spec, &$disabled, &$remapped): array {
            $out = [];
            foreach ($list as $f) {
                $cat  = strtolower((string) ($f['category'] ?? ''));
                $rule = $spec[$cat] ?? null;
                if ($rule === null) {
                    $out[] = $f;
                    continue;
                }
                if ($rule['enabled'] === false) {
                    if ($count) {
                        $disabled++;
                    }
                    continue;
                }
                if ($rule['severity'] !== null
                    && strtolower((string) ($f['severity'] ?? '')) !== $rule['severity']) {
                    $f['severity'] = $rule['severity'];
                    if ($count) {
                        $remapped++;
                    }
                }
                $out[] = $f;
            }
            return $out;
        };

        if (isset($results['all_findings']) && is_array($results['all_findings'])) {
            $results['all_findings'] = array_values($transform($results['all_findings'], true));
        }
        if (isset($results['agent_results']) && is_array($results['agent_results'])) {
            foreach ($results['agent_results'] as $agent => $data) {
                if (isset($data['findings']) && is_array($data['findings'])) {
                    $results['agent_results'][$agent]['findings'] = array_values($transform($data['findings'], false));
                }
            }
        }

        $results = self::recomputeSummary($results);

        return [$results, $disabled, $remapped];
    }

    /**
     * The effective state of every rule, merging the catalog with config.
     *
     * @param array<string,array{enabled:bool,severity:?string}> $spec
     * @return array<int,array{id:string,group:string,enabled:bool,severity:string}>
     */
    public static function describe(array $spec): array
    {
        $rows = [];
        foreach (self::CATALOG as $id => $group) {
            $rule = $spec[$id] ?? ['enabled' => true, 'severity' => null];
            $rows[] = [
                'id'       => $id,
                'group'    => $group,
                'enabled'  => $rule['enabled'],
                'severity' => $rule['severity'] ?? 'default',
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $results @return array<string,mixed> */
    private static function recomputeSummary(array $results): array
    {
        $all    = $results['all_findings'] ?? [];
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($all as $f) {
            $counts[Severity::clamp($f['severity'] ?? '')]++;
        }

        $summary                 = $results['summary'] ?? [];
        $summary['total_issues'] = count($all);
        $summary['critical']     = $counts['critical'];
        $summary['high']         = $counts['high'];
        $summary['medium']       = $counts['medium'];
        $summary['low']          = $counts['low'];
        if (isset($summary['by_severity'])) {
            $summary['by_severity'] = $counts;
        }

        $sorted = $all;
        usort($sorted, fn($a, $b) =>
            (Severity::ORDER[Severity::clamp($a['severity'] ?? '')] ?? 4)
            <=> (Severity::ORDER[Severity::clamp($b['severity'] ?? '')] ?? 4)
        );
        $summary['top_findings'] = array_slice($sorted, 0, 10);

        $results['summary'] = $summary;

        return $results;
    }
}
