<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Renders findings as JUnit XML — the de-facto format for CI "Tests" panels
 * (GitHub Actions test reporters, GitLab `junit` artifacts, Jenkins, CircleCI,
 * Azure DevOps "Tests" tab, Bitbucket, etc.).
 *
 * Each finding becomes a <testcase> with a <failure> so it surfaces as a failed
 * check; testcases are grouped into one <testsuite> per analyzer group. Output
 * is well-formed, escaped XML built without external deps.
 */
final class JUnitFormatter
{
    public function format(array $results): string
    {
        $findings = $this->collectFindings($results);
        $groups   = $this->groupByAnalyzer($findings);

        $total = count($findings);

        $lines   = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = sprintf(
            '<testsuites name="CodeGuardian AI" tests="%d" failures="%d">',
            $total,
            $total
        );

        foreach ($groups as $group => $items) {
            $lines[] = sprintf(
                '  <testsuite name="%s" tests="%d" failures="%d">',
                self::attr($group),
                count($items),
                count($items)
            );

            foreach ($items as $f) {
                $lines = array_merge($lines, $this->renderTestcase($f, $group));
            }

            $lines[] = '  </testsuite>';
        }

        $lines[] = '</testsuites>';

        return implode("\n", $lines) . "\n";
    }

    /** @param array<string,mixed> $f @return array<int,string> */
    private function renderTestcase(array $f, string $group): array
    {
        $severity = strtolower((string) ($f['severity'] ?? 'low'));
        $title    = (string) ($f['title'] ?? 'Issue');
        $file     = (string) ($f['file'] ?? '');
        $line     = (int) ($f['line_start'] ?? 0);
        $category = (string) ($f['category'] ?? $group);

        $name      = sprintf('[%s] %s', strtoupper($severity), $title);
        $classname = $file !== '' ? str_replace('/', '.', ltrim($file, '/')) : $group;

        $message = trim((string) ($f['description'] ?? $title));

        $body = [];
        if ($file !== '') {
            $body[] = $file . ($line > 0 ? ':' . $line : '');
        }
        if (! empty($f['recommendation'])) {
            $body[] = 'Fix: ' . $f['recommendation'];
        }
        if (! empty($f['code_snippet'])) {
            $body[] = 'Code: ' . $f['code_snippet'];
        }
        $bodyText = implode("\n", $body);

        return [
            sprintf(
                '    <testcase name="%s" classname="%s" file="%s" line="%d">',
                self::attr($name),
                self::attr($classname),
                self::attr($file),
                max(0, $line)
            ),
            sprintf(
                '      <failure type="%s" message="%s">%s</failure>',
                self::attr($severity),
                self::attr($message),
                self::text($bodyText)
            ),
            '    </testcase>',
        ];
    }

    /** @return array<int,array<string,mixed>> */
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
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function groupByAnalyzer(array $findings): array
    {
        $groups = [];
        foreach ($findings as $f) {
            $cat   = strtolower((string) ($f['category'] ?? 'general'));
            $group = RuleRegistry::CATALOG[$cat] ?? 'general';
            $groups[$group][] = $f;
        }
        ksort($groups);
        return $groups;
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function text(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1, 'UTF-8');
    }
}
