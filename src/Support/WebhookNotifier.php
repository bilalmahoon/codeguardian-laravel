<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use GuzzleHttp\Client;

/**
 * Builds chat-webhook payloads (Slack, Microsoft Teams, or a generic JSON shape)
 * from a CodeGuardian result and posts them. Payload builders are pure and
 * unit-testable; only send() touches the network.
 */
final class WebhookNotifier
{
    /**
     * @param  array<string,mixed> $results
     * @return array<string,mixed> Slack incoming-webhook payload
     */
    public static function slack(array $results, string $projectLabel = ''): array
    {
        $s     = self::stats($results);
        $title = trim(($projectLabel !== '' ? $projectLabel . ' — ' : '') . 'CodeGuardian report');

        $header = sprintf(
            '%s *%s*',
            $s['emoji'],
            $title
        );

        $line = sprintf(
            'Quality: *%s/100* (%s) · Risk: *%s/100* · Issues: *%d* (🔴 %d / 🟠 %d / 🟡 %d / 🟢 %d)',
            $s['score'], $s['grade'], $s['risk'],
            $s['total'], $s['critical'], $s['high'], $s['medium'], $s['low']
        );

        return [
            'text'   => "{$header}\n{$line}",
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $header]],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $line]],
            ],
        ];
    }

    /**
     * @param  array<string,mixed> $results
     * @return array<string,mixed> Microsoft Teams MessageCard payload
     */
    public static function teams(array $results, string $projectLabel = ''): array
    {
        $s     = self::stats($results);
        $title = trim(($projectLabel !== '' ? $projectLabel . ' — ' : '') . 'CodeGuardian report');

        return [
            '@type'      => 'MessageCard',
            '@context'   => 'http://schema.org/extensions',
            'themeColor' => $s['color'],
            'summary'    => $title,
            'title'      => $s['emoji'] . ' ' . $title,
            'sections'   => [[
                'facts' => [
                    ['name' => 'Quality', 'value' => "{$s['score']}/100 ({$s['grade']})"],
                    ['name' => 'Risk',    'value' => "{$s['risk']}/100"],
                    ['name' => 'Issues',  'value' => "{$s['total']}"],
                    ['name' => 'Critical/High', 'value' => "{$s['critical']} / {$s['high']}"],
                ],
            ]],
        ];
    }

    /**
     * @param  array<string,mixed> $results
     * @return array<string,mixed> Generic, integration-friendly JSON payload
     */
    public static function generic(array $results, string $projectLabel = ''): array
    {
        $s = self::stats($results);
        return [
            'project'  => $projectLabel,
            'quality'  => $s['score'],
            'grade'    => $s['grade'],
            'risk'     => $s['risk'],
            'issues'   => [
                'total'    => $s['total'],
                'critical' => $s['critical'],
                'high'     => $s['high'],
                'medium'   => $s['medium'],
                'low'      => $s['low'],
            ],
        ];
    }

    /**
     * Slack payload summarising a `codeguardian:sentry` run: which production
     * issues were triaged, where they live, and what happened (fixed / preview /
     * analyzed / could-not-fix).
     *
     * Each item: {id?, title, file?, line?, permalink?, events?, status,
     *             root_cause?, applied?, tests?}  where status is one of:
     *   fixed | preview | analyzed | unresolvable | no-file | error
     *
     * When $interactive is true, issues that are not yet fixed get a one-click
     * "Fix" button (Slack Interactivity → cg_sentry_fix action) so the channel
     * can trigger a safe auto-fix.
     *
     * @param  array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public static function sentrySummary(array $items, string $projectLabel = '', bool $interactive = false): array
    {
        $fixed   = 0;
        $preview = 0;
        $failed  = 0;
        foreach ($items as $it) {
            $st = (string) ($it['status'] ?? '');
            if ($st === 'fixed')                              { $fixed++; }
            elseif ($st === 'preview')                        { $preview++; }
            elseif (in_array($st, ['unresolvable', 'no-file', 'error'], true)) { $failed++; }
        }

        $emoji  = $fixed > 0 ? '🛠️' : ($failed > 0 ? '🔴' : '🔍');
        $prefix = $projectLabel !== '' ? "{$projectLabel} — " : '';
        $header = sprintf('%s *%sCodeGuardian × Sentry* — %d issue%s triaged',
            $emoji, $prefix, count($items), count($items) === 1 ? '' : 's');
        $line   = sprintf('Fixed: *%d*  ·  Preview: *%d*  ·  Needs attention: *%d*', $fixed, $preview, $failed);

        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $header]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $line]],
        ];

        foreach (array_slice($items, 0, 10) as $it) {
            $blocks[] = ['type' => 'divider'];
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => self::sentryItemText($it)]];

            // Offer a one-click fix for issues that are not fixed yet.
            $fixable = $interactive
                && ! empty($it['id'])
                && in_array((string) ($it['status'] ?? ''), ['analyzed', 'preview', 'unresolvable'], true);

            if ($fixable) {
                $blocks[] = [
                    'type'     => 'actions',
                    'block_id' => 'cg_issue_' . $it['id'],
                    'elements' => [[
                        'type'      => 'button',
                        'style'     => 'primary',
                        'text'      => ['type' => 'plain_text', 'text' => '🛠️ Fix it', 'emoji' => true],
                        'action_id' => 'cg_sentry_fix',
                        'value'     => (string) $it['id'],
                    ]],
                ];
            }
        }

        return [
            'text'   => "{$header}\n{$line}",
            'blocks' => $blocks,
        ];
    }

    /** @param array<string,mixed> $it */
    private static function sentryItemText(array $it): string
    {
        $badge = match ((string) ($it['status'] ?? '')) {
            'fixed'        => '🛠️ *Fixed*',
            'preview'      => '📝 *Fix ready (preview)*',
            'analyzed'     => '🔍 *Analyzed*',
            'unresolvable' => '⚠️ *Could not auto-fix*',
            'no-file'      => '❓ *Source not found in repo*',
            default        => '🔴 *Error*',
        };

        $title = (string) ($it['title'] ?? 'Unknown issue');
        if (! empty($it['permalink'])) {
            $title = '<' . $it['permalink'] . '|' . self::escapeSlack($title) . '>';
        } else {
            $title = self::escapeSlack($title);
        }

        $text = "{$badge}\n{$title}";

        if (! empty($it['file'])) {
            $loc = '`' . $it['file'] . (! empty($it['line']) ? ':' . $it['line'] : '') . '`';
            $text .= "\n📄 {$loc}";
        }
        if (! empty($it['events'])) {
            $text .= "  ·  {$it['events']}× in prod";
        }
        if (! empty($it['root_cause'])) {
            $text .= "\n_" . self::escapeSlack(self::clip((string) $it['root_cause'], 240)) . '_';
        }
        if (isset($it['tests']) && $it['tests'] !== '') {
            $t = (string) $it['tests'];
            $icon = $t === 'passed' ? '✅' : ($t === 'failed' ? '❌' : '⏭️');
            $text .= "\n{$icon} Tests: {$t}";
        }

        return $text;
    }

    private static function clip(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    private static function escapeSlack(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    /** Build the payload for a named format. @return array<string,mixed> */
    public static function build(string $format, array $results, string $projectLabel = ''): array
    {
        return match (strtolower($format)) {
            'teams'   => self::teams($results, $projectLabel),
            'generic' => self::generic($results, $projectLabel),
            default   => self::slack($results, $projectLabel),
        };
    }

    /** POST a payload to a webhook URL. Returns true on a 2xx response. */
    public static function send(string $url, array $payload, ?Client $client = null): bool
    {
        $client ??= new Client(['timeout' => 30]);
        try {
            $response = $client->post($url, ['json' => $payload]);
            $code = $response->getStatusCode();
            return $code >= 200 && $code < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string,mixed> $results
     * @return array{score:int,grade:string,risk:int,total:int,critical:int,high:int,medium:int,low:int,emoji:string,color:string}
     */
    private static function stats(array $results): array
    {
        $summary = $results['summary'] ?? [];
        $bySev   = $summary['by_severity'] ?? $summary;

        $critical = (int) ($bySev['critical'] ?? 0);
        $high     = (int) ($bySev['high'] ?? 0);
        $medium   = (int) ($bySev['medium'] ?? 0);
        $low      = (int) ($bySev['low'] ?? 0);
        $total    = (int) ($summary['total_issues'] ?? ($critical + $high + $medium + $low));

        $emoji = $critical > 0 ? '🔴' : ($high > 0 ? '🟠' : ($total > 0 ? '🟡' : '🟢'));
        $color = $critical > 0 ? 'D7263D' : ($high > 0 ? 'F46036' : ($total > 0 ? 'F4C430' : '2EC4B6'));

        return [
            'score'    => (int) ($results['overall_score'] ?? 100),
            'grade'    => (string) ($results['grade'] ?? '—'),
            'risk'     => (int) ($summary['risk_score'] ?? 0),
            'total'    => $total,
            'critical' => $critical,
            'high'     => $high,
            'medium'   => $medium,
            'low'      => $low,
            'emoji'    => $emoji,
            'color'    => $color,
        ];
    }
}
