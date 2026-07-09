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
     * @return array<int,array{ts:string,time:string,user:string,text:string,preview:string}>
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

    /**
     * A single message by its timestamp (for the detail page). Uses Slack's
     * latest+inclusive window so we fetch exactly that one message.
     *
     * @return array{ts:string,time:string,user:string,text:string,preview:string}|null
     */
    public function message(string $channelId, string $ts): ?array
    {
        $data = $this->get('conversations.history', [
            'channel'   => $channelId,
            'latest'    => $ts,
            'oldest'    => $ts,
            'inclusive' => 'true',
            'limit'     => 1,
        ]);

        if (! is_array($data) || ($data['ok'] ?? false) !== true) {
            return null;
        }

        $messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
        $raw      = $messages[0] ?? null;

        return is_array($raw) ? self::normaliseOne($raw) : null;
    }

    /** A shareable Slack permalink for a message, or null. */
    public function permalink(string $channelId, string $ts): ?string
    {
        $data = $this->get('chat.getPermalink', ['channel' => $channelId, 'message_ts' => $ts]);
        if (is_array($data) && ($data['ok'] ?? false) === true && ! empty($data['permalink'])) {
            return (string) $data['permalink'];
        }
        return null;
    }

    // ─── Pure helpers (no network) ──────────────────────────────────────────

    /**
     * Normalise Slack's `messages` payload: keep real user/bot messages, format
     * the timestamp, extract text from rich (block/attachment) messages, and
     * drop join/leave/system noise.
     *
     * @param  array<int,mixed> $raw
     * @return array<int,array{ts:string,time:string,user:string,text:string,preview:string}>
     */
    public static function normaliseMessages(array $raw): array
    {
        $out = [];
        foreach ($raw as $m) {
            if (! is_array($m) || ($m['type'] ?? 'message') !== 'message') {
                continue;
            }
            $subtype = (string) ($m['subtype'] ?? '');
            if (in_array($subtype, ['channel_join', 'channel_leave', 'channel_topic', 'channel_purpose'], true)) {
                continue;
            }
            $out[] = self::normaliseOne($m);
        }

        return $out;
    }

    /**
     * Normalise one Slack message into a display row.
     *
     * @param  array<string,mixed> $m
     * @return array{ts:string,time:string,user:string,text:string,preview:string}
     */
    public static function normaliseOne(array $m): array
    {
        $ts   = (string) ($m['ts'] ?? '');
        $text = self::extractText($m);

        return [
            'ts'      => $ts,
            'time'    => $ts !== '' ? date('M j, H:i', (int) $ts) : '',
            'user'    => (string) ($m['username'] ?? $m['user'] ?? $m['bot_id'] ?? 'unknown'),
            'text'    => $text,
            'preview' => self::clip($text, 220),
        ];
    }

    /**
     * Best-effort human text for a Slack message: the top-level `text`, else the
     * readable parts of Block Kit blocks, else attachment fallbacks. Bots very
     * often send text="" with everything in blocks/attachments — this is what
     * turns a useless "(rich message)" into the actual content.
     *
     * @param  array<string,mixed> $m
     */
    public static function extractText(array $m): string
    {
        $top = trim((string) ($m['text'] ?? ''));
        if ($top !== '') {
            return $top;
        }

        $parts = [];

        foreach (is_array($m['blocks'] ?? null) ? $m['blocks'] : [] as $block) {
            if (is_array($block)) {
                $parts[] = self::textFromBlock($block);
            }
        }

        foreach (is_array($m['attachments'] ?? null) ? $m['attachments'] : [] as $att) {
            if (! is_array($att)) {
                continue;
            }
            foreach (['pretext', 'title', 'text', 'fallback'] as $k) {
                if (! empty($att[$k])) {
                    $parts[] = trim((string) $att[$k]);
                }
            }
            foreach (is_array($att['fields'] ?? null) ? $att['fields'] : [] as $field) {
                if (is_array($field)) {
                    $label = trim((string) ($field['title'] ?? ''));
                    $value = trim((string) ($field['value'] ?? ''));
                    $parts[] = trim($label . ($label && $value ? ': ' : '') . $value);
                }
            }
        }

        $joined = trim(implode("\n", array_filter(array_map('trim', $parts), fn($s) => $s !== '')));

        return $joined !== '' ? $joined : '(no text content)';
    }

    /**
     * Pull readable text out of a single Block Kit block.
     *
     * @param  array<string,mixed> $block
     */
    private static function textFromBlock(array $block): string
    {
        $type = (string) ($block['type'] ?? '');

        // section / header: {text:{text:"…"}}
        if (isset($block['text']['text'])) {
            return trim((string) $block['text']['text']);
        }

        // context / actions: {elements:[{text:"…"} | {text:{text:"…"}}]}
        if ($type === 'context' && is_array($block['elements'] ?? null)) {
            $bits = [];
            foreach ($block['elements'] as $el) {
                if (is_array($el)) {
                    $bits[] = isset($el['text']['text']) ? (string) $el['text']['text'] : (string) ($el['text'] ?? '');
                }
            }
            return trim(implode(' ', array_filter($bits)));
        }

        // rich_text: nested elements[].elements[].text
        if ($type === 'rich_text' && is_array($block['elements'] ?? null)) {
            $bits = [];
            foreach ($block['elements'] as $section) {
                foreach (is_array($section['elements'] ?? null) ? $section['elements'] : [] as $el) {
                    if (is_array($el) && isset($el['text'])) {
                        $bits[] = (string) $el['text'];
                    }
                }
            }
            return trim(implode('', $bits));
        }

        return '';
    }

    private static function clip(string $s, int $len): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
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
