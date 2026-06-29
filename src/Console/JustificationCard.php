<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Console;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders a compact "why this change" justification card for a single finding.
 *
 * Every refactoring recommendation is accompanied by its reasoning — why it
 * matters, the expected benefit, risk/effort, breaking-change likelihood, and
 * the taxonomy it maps to — so a change is never proposed without justification.
 *
 * The line builder is pure (returns an array of tagged strings) so it can be
 * unit-tested without a terminal.
 */
final class JustificationCard
{
    /**
     * Build the card lines for a finding. Only rows with data are emitted.
     *
     * @param array<string,mixed> $finding
     * @return array<int,string>
     */
    public static function lines(array $finding): array
    {
        $sev   = strtolower((string) ($finding['severity'] ?? 'medium'));
        $title = (string) ($finding['title'] ?? 'Issue');

        $sevColor = match ($sev) {
            'critical' => 'red',
            'high'     => 'yellow',
            'medium'   => 'yellow',
            default    => 'green',
        };

        $lines   = [];
        $lines[] = sprintf('  <fg=%s;options=bold>%s</> <options=bold>%s</>', $sevColor, strtoupper($sev), $title);

        $why = $finding['root_cause'] ?? $finding['description'] ?? '';
        if ($why !== '') {
            $lines[] = '    <fg=gray>Why</>       ' . self::clip((string) $why, 90);
        }

        foreach ([
            'impact'        => 'Benefit',
            'breaking_risk' => 'Breaking',
            'effort'        => 'Effort',
            'confidence'    => 'Confidence',
        ] as $key => $label) {
            if (! empty($finding[$key])) {
                $lines[] = '    <fg=gray>' . str_pad($label, 9) . '</> ' . self::clip((string) $finding[$key], 90);
            }
        }

        $tax = self::taxonomy($finding);
        if ($tax !== '') {
            $lines[] = '    <fg=gray>Standard</>  ' . $tax;
        }

        $fix = $finding['recommendation'] ?? $finding['suggested_fix'] ?? '';
        if ($fix !== '') {
            $lines[] = '    <fg=green>Fix</>       ' . self::clip((string) $fix, 90);
        }

        return $lines;
    }

    /** @param array<string,mixed> $finding */
    public static function render(OutputInterface $output, array $finding): void
    {
        foreach (self::lines($finding) as $line) {
            $output->writeln($line);
        }
    }

    /** @param array<string,mixed> $finding */
    private static function taxonomy(array $finding): string
    {
        $bits = [];
        if (! empty($finding['owasp'])) {
            $bits[] = 'OWASP ' . $finding['owasp'];
        }
        if (! empty($finding['cwe'])) {
            $bits[] = (string) $finding['cwe'];
        }
        if (! empty($finding['principle'])) {
            $bits[] = (string) $finding['principle'];
        }
        return implode(' · ', $bits);
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max - 1) . '…';
    }
}
