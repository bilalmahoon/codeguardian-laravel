<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use CodeGuardian\Laravel\Analyzers\Severity;

/**
 * Renders findings as GitHub Actions workflow commands so they appear as inline
 * annotations on the PR "Files changed" view — with ZERO setup (no SARIF upload,
 * no extra action). Just echo the lines in the job log.
 *
 *   ::error file=app/X.php,line=10,title=SQL injection::User input in query
 *   ::warning file=app/Y.php,line=5,title=N+1 query::...
 *   ::notice file=app/Z.php,line=1,title=Magic number::...
 *
 * Escaping follows GitHub's workflow-command rules: message data escapes
 * %, CR, LF; property values additionally escape ',' and ':'.
 *
 * @see https://docs.github.com/actions/using-workflows/workflow-commands-for-github-actions
 */
final class GitHubAnnotations
{
    /**
     * Build the workflow-command lines for a result set.
     *
     * @param array<string,mixed> $results
     * @param int $max  cap the number of annotations (0 = no cap). GitHub only
     *                  surfaces ~10 of each level per step, so a cap avoids noise.
     * @return array<int,string>
     */
    public static function lines(array $results, int $max = 0): array
    {
        $findings = self::collect($results);

        // Most severe first so the cap keeps the important annotations.
        usort($findings, fn($a, $b) =>
            (Severity::ORDER[Severity::clamp($a['severity'] ?? '')] ?? 4)
            <=> (Severity::ORDER[Severity::clamp($b['severity'] ?? '')] ?? 4)
        );

        if ($max > 0 && count($findings) > $max) {
            $findings = array_slice($findings, 0, $max);
        }

        $lines = [];
        foreach ($findings as $f) {
            $lines[] = self::line($f);
        }

        return $lines;
    }

    /** @param array<string,mixed> $f */
    public static function line(array $f): string
    {
        $level = match (strtolower((string) ($f['severity'] ?? 'low'))) {
            'critical', 'high' => 'error',
            'medium'           => 'warning',
            default            => 'notice',
        };

        $props = [];
        $file = trim((string) ($f['file'] ?? ''));
        if ($file !== '') {
            $props[] = 'file=' . self::prop(str_replace('\\', '/', $file));
        }
        $line = max(1, (int) ($f['line_start'] ?? 1));
        $props[] = 'line=' . $line;

        $title = self::title($f);
        if ($title !== '') {
            $props[] = 'title=' . self::prop($title);
        }

        $message = trim((string) ($f['description'] ?? ($f['title'] ?? 'Issue')));
        if (! empty($f['recommendation'])) {
            $message .= "\nFix: " . $f['recommendation'];
        }

        $propStr = $props === [] ? '' : ' ' . implode(',', $props);

        return "::{$level}{$propStr}::" . self::data($message);
    }

    /** @return array<int,array<string,mixed>> */
    private static function collect(array $results): array
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

    /** @param array<string,mixed> $f */
    private static function title(array $f): string
    {
        $tag = '';
        if (! empty($f['cwe'])) {
            $tag = (string) $f['cwe'];
        } elseif (! empty($f['owasp'])) {
            $tag = (string) $f['owasp'];
        }

        $title = trim((string) ($f['title'] ?? ''));
        $prefix = 'CodeGuardian';
        if ($tag !== '') {
            $prefix .= " {$tag}";
        }

        return $title !== '' ? "{$prefix}: {$title}" : $prefix;
    }

    /** Escape message data: %, CR, LF. */
    private static function data(string $value): string
    {
        return str_replace(["%", "\r", "\n"], ["%25", "%0D", "%0A"], $value);
    }

    /** Escape a property value: message rules + ',' and ':'. */
    private static function prop(string $value): string
    {
        return str_replace(
            ["%", "\r", "\n", ",", ":"],
            ["%25", "%0D", "%0A", "%2C", "%3A"],
            $value
        );
    }
}
