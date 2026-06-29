<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Parses composer dependency metadata and security advisories into CodeGuardian
 * findings. All methods are pure (string/array in, array out) so they can be
 * unit-tested without Composer or the network; the command layer handles IO.
 */
final class DependencyAudit
{
    /**
     * Extract [name => version] from a composer.lock document.
     *
     * @return array<string,string>
     */
    public static function parseLock(string $lockJson): array
    {
        $doc = json_decode($lockJson, true);
        if (! is_array($doc)) {
            return [];
        }

        $packages = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($doc[$section] ?? [] as $pkg) {
                $name = $pkg['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $packages[$name] = (string) ($pkg['version'] ?? 'unknown');
                }
            }
        }

        ksort($packages);
        return $packages;
    }

    /**
     * Convert `composer audit --format=json` output into findings.
     *
     * @param  array<string,mixed> $audit
     * @param  array<string,string> $versions  name => installed version (optional)
     * @return list<array<string,mixed>>
     */
    public static function fromComposerAudit(array $audit, array $versions = []): array
    {
        $findings = [];

        foreach ($audit['advisories'] ?? [] as $package => $advisories) {
            foreach ((array) $advisories as $adv) {
                $findings[] = self::advisoryToFinding(
                    (string) $package,
                    is_array($adv) ? $adv : [],
                    $versions[$package] ?? null
                );
            }
        }

        // Abandoned packages are a maintenance/security risk too.
        foreach ($audit['abandoned'] ?? [] as $package => $replacement) {
            $repl = is_string($replacement) && $replacement !== ''
                ? "Use {$replacement} instead."
                : 'No replacement suggested — find a maintained alternative.';
            $findings[] = [
                'category'       => 'abandoned_dependency',
                'severity'       => 'medium',
                'title'          => "Abandoned package: {$package}",
                'description'    => "The package {$package} is marked abandoned and will not receive security fixes.",
                'file'           => 'composer.lock',
                'line_start'     => 0,
                'recommendation' => $repl,
                'confidence'     => 'high',
                'principle'      => 'Security: maintained dependencies',
            ];
        }

        return $findings;
    }

    /**
     * Convert a Packagist security-advisories API response into findings.
     * Response shape: { "advisories": { "pkg/name": [ {...}, ... ] } }
     *
     * @param  array<string,mixed> $response
     * @param  array<string,string> $versions
     * @return list<array<string,mixed>>
     */
    public static function fromPackagist(array $response, array $versions = []): array
    {
        $findings = [];
        foreach ($response['advisories'] ?? [] as $package => $advisories) {
            foreach ((array) $advisories as $adv) {
                $findings[] = self::advisoryToFinding(
                    (string) $package,
                    is_array($adv) ? $adv : [],
                    $versions[$package] ?? null
                );
            }
        }
        return $findings;
    }

    /**
     * @param  array<string,mixed> $adv
     * @return array<string,mixed>
     */
    private static function advisoryToFinding(string $package, array $adv, ?string $version): array
    {
        $cve      = (string) ($adv['cve'] ?? '');
        $title    = (string) ($adv['title'] ?? 'Known security advisory');
        $link     = (string) ($adv['link'] ?? ($adv['sources'][0]['remoteId'] ?? ''));
        $affected = (string) ($adv['affectedVersions'] ?? $adv['affected_versions'] ?? '');
        $severity = self::mapSeverity((string) ($adv['severity'] ?? ''));
        $verLabel = $version ? " (installed {$version})" : '';

        $refs = [];
        if ($link !== '') {
            $refs[] = $link;
        }

        return [
            'category'       => 'dependency_vulnerability',
            'severity'       => $severity,
            'title'          => trim("{$package}{$verLabel}: {$title}"),
            'description'    => trim(($cve !== '' ? "{$cve}. " : '') . ($affected !== '' ? "Affected versions: {$affected}." : '')) ?: $title,
            'file'           => 'composer.lock',
            'line_start'     => 0,
            'recommendation' => "Update {$package} to a patched version (`composer update {$package}`).",
            'confidence'     => 'high',
            'cwe'            => '',
            'owasp'          => 'A06:2021-Vulnerable and Outdated Components',
            'references'     => $refs,
            'principle'      => 'Security: patch known CVEs',
        ];
    }

    private static function mapSeverity(string $raw): string
    {
        return match (strtolower(trim($raw))) {
            'critical'        => 'critical',
            'high'            => 'high',
            'medium', 'moderate' => 'medium',
            'low'             => 'low',
            default           => 'high', // unknown advisory severity → treat as high
        };
    }

    /**
     * Build a CodeGuardian-style result envelope from dependency findings so it
     * can flow through the standard reporters.
     *
     * @param  list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    public static function toResult(array $findings, int $packageCount, string $projectName = ''): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($findings as $f) {
            $sev = $f['severity'] ?? 'low';
            $counts[$sev] = ($counts[$sev] ?? 0) + 1;
        }

        return [
            'files_scanned' => 1,
            'total_lines'   => $packageCount,
            'project_name'  => $projectName,
            'project_type'  => 'composer',
            'engine'        => 'dependency-audit',
            'scanned_at'    => date('c'),
            'all_findings'  => $findings,
            'agent_results' => ['dependency' => ['agent' => 'dependency', 'findings' => $findings]],
            'summary'       => array_merge(
                ['total_issues' => count($findings)],
                $counts,
                ['by_severity' => $counts]
            ),
        ];
    }
}
