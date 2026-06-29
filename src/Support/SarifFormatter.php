<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Renders findings as SARIF 2.1.0 — the OASIS standard interchange format for
 * static-analysis results.
 *
 * SARIF is ingested natively by:
 *   - GitHub code scanning  (Security tab / PR annotations)
 *   - Azure DevOps          (SARIF SAST Scans Tab extension)
 *   - GitLab                (SAST report artifact)
 *   - VS Code SARIF viewer, and most other tooling
 *
 * The output is strict, schema-valid SARIF (required fields always present)
 * which maximises cross-platform compatibility. Severities are mapped to both
 * SARIF `level` and the GitHub `security-severity` property so the security
 * tab orders alerts correctly.
 */
final class SarifFormatter
{
    public const TOOL_NAME    = 'CodeGuardian AI';
    public const TOOL_VERSION = '1.0.0';
    public const INFO_URI     = 'https://github.com/bilalmahoon/codeguardian-laravel';
    public const SCHEMA       = 'https://json.schemastore.org/sarif-2.1.0.json';

    /** Render the SARIF document as pretty JSON. */
    public function format(array $results): string
    {
        return json_encode(
            $this->build($results),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Build the SARIF document as a PHP array (testable without JSON).
     *
     * @return array<string,mixed>
     */
    public function build(array $results): array
    {
        $findings = $this->collectFindings($results);

        [$rules, $ruleIndex] = $this->buildRules($findings);

        $sarifResults = [];
        foreach ($findings as $f) {
            $sarifResults[] = $this->buildResult($f, $ruleIndex);
        }

        return [
            '$schema' => self::SCHEMA,
            'version' => '2.1.0',
            'runs'    => [[
                'tool' => [
                    'driver' => [
                        'name'           => self::TOOL_NAME,
                        'informationUri' => self::INFO_URI,
                        'version'        => self::TOOL_VERSION,
                        'rules'          => array_values($rules),
                    ],
                ],
                'results' => $sarifResults,
            ]],
        ];
    }

    /**
     * Gather a flat findings list from a result structure (prefers
     * all_findings, falls back to per-agent findings).
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectFindings(array $results): array
    {
        if (! empty($results['all_findings']) && is_array($results['all_findings'])) {
            return array_values($results['all_findings']);
        }

        $findings = [];
        foreach ($results['agent_results'] ?? [] as $agent => $data) {
            if ($agent === 'qa') {
                continue;
            }
            foreach ($data['findings'] ?? [] as $f) {
                $findings[] = $f;
            }
        }

        return $findings;
    }

    /**
     * Build the distinct rule set (one per category) and an id→index map.
     *
     * @param array<int,array<string,mixed>> $findings
     * @return array{0: array<string,array<string,mixed>>, 1: array<string,int>}
     */
    private function buildRules(array $findings): array
    {
        $rules = [];
        $index = [];

        foreach ($findings as $f) {
            $category = (string) ($f['category'] ?? 'general');
            if (isset($rules[$category])) {
                continue;
            }

            $level = self::levelFor((string) ($f['severity'] ?? 'low'));
            $tags  = self::tagsFor($f);

            $rule = [
                'id'               => $category,
                'name'             => self::pascalCase($category),
                'shortDescription' => ['text' => self::humanize($category)],
                'fullDescription'  => ['text' => self::clip((string) ($f['description'] ?? self::humanize($category)), 1000)],
                'defaultConfiguration' => ['level' => $level],
                'properties'       => array_filter([
                    'tags'              => $tags,
                    'security-severity' => self::securitySeverity((string) ($f['severity'] ?? 'low')),
                ]),
            ];

            $help = (string) ($f['recommendation'] ?? '');
            if ($help !== '') {
                $rule['help'] = ['text' => self::clip($help, 1000)];
            }

            $helpUri = self::helpUri($f);
            if ($helpUri !== null) {
                $rule['helpUri'] = $helpUri;
            }

            $index[$category] = count($rules);
            $rules[$category] = $rule;
        }

        return [$rules, $index];
    }

    /**
     * Build a single SARIF result object.
     *
     * @param array<string,mixed> $f
     * @param array<string,int>   $ruleIndex
     * @return array<string,mixed>
     */
    private function buildResult(array $f, array $ruleIndex): array
    {
        $category = (string) ($f['category'] ?? 'general');
        $severity = (string) ($f['severity'] ?? 'low');
        $startLine = max(1, (int) ($f['line_start'] ?? 1));
        $endLine   = max($startLine, (int) ($f['line_end'] ?? $startLine));

        $message = trim((string) ($f['title'] ?? 'Issue'));
        $desc    = trim((string) ($f['description'] ?? ''));
        if ($desc !== '') {
            $message .= ' — ' . $desc;
        }

        $region = ['startLine' => $startLine, 'endLine' => $endLine];
        $snippet = (string) ($f['code_snippet'] ?? '');
        if ($snippet !== '') {
            $region['snippet'] = ['text' => $snippet];
        }

        $result = [
            'ruleId'    => $category,
            'ruleIndex' => $ruleIndex[$category] ?? 0,
            'level'     => self::levelFor($severity),
            'message'   => ['text' => self::clip($message, 2000)],
            'locations' => [[
                'physicalLocation' => [
                    'artifactLocation' => ['uri' => self::uri((string) ($f['file'] ?? ''))],
                    'region'           => $region,
                ],
            ]],
            'partialFingerprints' => [
                'codeguardian/v1' => Baseline::fingerprint($f),
            ],
            'properties' => array_filter([
                'security-severity' => self::securitySeverity($severity),
                'confidence'        => $f['confidence']    ?? null,
                'impact'            => $f['impact']         ?? null,
                'effort'            => $f['effort']         ?? null,
                'breakingRisk'      => $f['breaking_risk']  ?? null,
                'rootCause'         => $f['root_cause']     ?? null,
                'owasp'             => $f['owasp']          ?? null,
                'cwe'               => $f['cwe']            ?? null,
                'principle'         => $f['principle']      ?? null,
            ], fn($v) => $v !== null && $v !== ''),
        ];

        return $result;
    }

    // ─── Mapping helpers ────────────────────────────────────────────────────

    /** SARIF level: error | warning | note. */
    public static function levelFor(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical', 'high' => 'error',
            'medium'           => 'warning',
            default            => 'note',
        };
    }

    /** GitHub security-severity (CVSS-like 0–10) used to order the security tab. */
    public static function securitySeverity(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical' => '9.5',
            'high'     => '8.0',
            'medium'   => '5.5',
            default    => '3.0',
        };
    }

    /** @param array<string,mixed> $f @return array<int,string> */
    private static function tagsFor(array $f): array
    {
        $tags = [(string) ($f['category'] ?? 'general')];

        if (! empty($f['owasp'])) {
            $tags[] = 'security';
            $tags[] = 'external/cwe/owasp';
        }
        if (! empty($f['cwe'])) {
            $tags[] = 'external/cwe/' . strtolower(str_replace('CWE-', 'cwe-', (string) $f['cwe']));
        }
        if (! empty($f['principle'])) {
            $tags[] = (string) $f['principle'];
        }

        return array_values(array_unique($tags));
    }

    /** @param array<string,mixed> $f */
    private static function helpUri(array $f): ?string
    {
        if (! empty($f['cwe']) && preg_match('/CWE-(\d+)/i', (string) $f['cwe'], $m)) {
            return "https://cwe.mitre.org/data/definitions/{$m[1]}.html";
        }
        if (! empty($f['owasp'])) {
            return 'https://owasp.org/Top10/';
        }
        return null;
    }

    private static function uri(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    private static function humanize(string $category): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $category));
    }

    private static function pascalCase(string $category): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $category)));
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim($text);
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1) . '…';
    }
}
