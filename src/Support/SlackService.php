<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use GuzzleHttp\Client;

/**
 * Read side of the Slack integration: pulls recent messages from the channels
 * your team uses for development/incident chatter, so engineers can investigate
 * from inside CodeGuardian instead of tab-hopping.
 *
 * Uses a Slack bot token (xoxb-…) with `channels:history` + `channels:read`.
 * Network is isolated to get()/post() so parsers stay unit-testable, and the
 * message normaliser is a pure static helper.
 */
final class SlackService
{
    private const API = 'https://slack.com/api';

    /**
     * @param array<int,array{id:string,label:string}> $channels
     */
    public function __construct(
        private readonly string $token,
        private readonly array $channels = [],
        private ?Client $http = null,
    ) {
        $this->http ??= new Client(['timeout' => 20]);
    }

    public static function fromConfig(?Client $http = null): self
    {
        $channels = [];
        foreach ((array) config('codeguardian.slack.channels', []) as $ch) {
            if (is_array($ch) && ! empty($ch['id'])) {
                $channels[] = ['id' => (string) $ch['id'], 'label' => (string) ($ch['label'] ?? $ch['id'])];
            }
        }

        return new self((string) config('codeguardian.slack.bot_token', ''), $channels, $http);
    }

    public function configured(): bool
    {
        return $this->token !== '' && $this->channels !== [];
    }

    public function missingConfig(): array
    {
        $missing = [];
        if ($this->token === '')   { $missing[] = 'CODEGUARDIAN_SLACK_BOT_TOKEN'; }
        if ($this->channels === []) { $missing[] = 'CODEGUARDIAN_SLACK_CHANNELS'; }
        return $missing;
    }

    /** @return array<int,array{id:string,label:string}> */
    public function channels(): array
    {
        return $this->channels;
    }

    public function defaultChannel(): ?string
    {
        return $this->channels[0]['id'] ?? null;
    }

    public function channelLabel(string $id): string
    {
        foreach ($this->channels as $ch) {
            if ($ch['id'] === $id) {
                return $ch['label'];
            }
        }
        return $id;
    }

    /**
     * Recent messages in a channel, newest-first, normalised for display.
     *
     * @return array<int,array{ts:string,time:string,user:string,text:string}>
     */
    public function messages(string $channelId, int $limit = 30): array
    {
        $data = $this->get('conversations.history', [
            'channel' => $channelId,
            'limit'   => max(1, min($limit, 100)),
        ]);

        if (! is_array($data) || ($data['ok'] ?? false) !== true) {
            return [];
        }

        return self::normaliseMessages(is_array($data['messages'] ?? null) ? $data['messages'] : []);
    }

    // ─── Pure helper (no network) ───────────────────────────────────────────

    /**
     * Normalise Slack's `messages` payload: keep real user messages, format the
     * timestamp, and drop join/leave/system noise.
     *
     * @param  array<int,mixed> $raw
     * @return array<int,array{ts:string,time:string,user:string,text:string}>
     */
    public static function normaliseMessages(array $raw): array
    {
        $out = [];
        foreach ($raw as $m) {
            if (! is_array($m) || ($m['type'] ?? 'message') !== 'message') {
                continue;
            }
            // Skip channel-join/leave and other subtype noise, but keep bot posts.
            $subtype = (string) ($m['subtype'] ?? '');
            if (in_array($subtype, ['channel_join', 'channel_leave', 'channel_topic', 'channel_purpose'], true)) {
                continue;
            }

            $ts   = (string) ($m['ts'] ?? '');
            $text = trim((string) ($m['text'] ?? ''));
            if ($text === '' && empty($m['attachments']) && empty($m['blocks'])) {
                continue;
            }

            $out[] = [
                'ts'   => $ts,
                'time' => $ts !== '' ? date('M j, H:i', (int) $ts) : '',
                'user' => (string) ($m['user'] ?? $m['username'] ?? $m['bot_id'] ?? 'unknown'),
                'text' => $text !== '' ? $text : '(rich message)',
            ];
        }

        return $out;
    }

    // ─── Network (isolated) ─────────────────────────────────────────────────

    /**
     * @param  array<string,scalar> $query
     * @return array<mixed>|null
     */
    private function get(string $method, array $query)
    {
        try {
            $response = $this->http->get(self::API . '/' . $method, [
                'headers' => ['Authorization' => 'Bearer ' . $this->token],
                'query'   => $query,
            ]);
            $decoded = json_decode((string) $response->getBody(), true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
