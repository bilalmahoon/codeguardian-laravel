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
