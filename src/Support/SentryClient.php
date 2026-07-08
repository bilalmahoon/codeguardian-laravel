<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use GuzzleHttp\Client;

/**
 * Thin client for the Sentry REST API plus the pure helpers that turn a Sentry
 * event into something actionable: the exact in-app source file + line where the
 * error occurred, and a map from Sentry's (often container-absolute) path to a
 * path inside the current project.
 *
 * Network access is isolated to a single get()/put() pair so every parser here
 * is deterministic and unit-testable without hitting Sentry.
 */
final class SentryClient
{
    /** Source roots we trust when anchoring a foreign (container) path to this repo. */
    private const SOURCE_ANCHORS = ['app/', 'Modules/', 'src/', 'routes/', 'database/', 'config/', 'tests/', 'lib/'];

    public function __construct(
        private readonly string $token,
        private readonly string $organization,
        private readonly string $project,
        private readonly string $baseUrl = 'https://sentry.io',
        private readonly string $environment = '',
        private ?Client $http = null,
    ) {
        $this->http ??= new Client(['timeout' => 30]);
    }

    public static function fromConfig(?Client $http = null): self
    {
        return new self(
            (string) config('codeguardian.sentry.token', ''),
            (string) config('codeguardian.sentry.organization', ''),
            (string) config('codeguardian.sentry.project', ''),
            (string) (config('codeguardian.sentry.url', 'https://sentry.io') ?: 'https://sentry.io'),
            (string) config('codeguardian.sentry.environment', ''),
            $http,
        );
    }

    /** True when enough is configured to talk to Sentry. */
    public function configured(): bool
    {
        return $this->token !== '' && $this->organization !== '' && $this->project !== '';
    }

    /** Human-readable list of what is missing (for a helpful error message). */
    public function missingConfig(): array
    {
        $missing = [];
        if ($this->token === '')        { $missing[] = 'CODEGUARDIAN_SENTRY_TOKEN'; }
        if ($this->organization === '') { $missing[] = 'CODEGUARDIAN_SENTRY_ORG'; }
        if ($this->project === '')      { $missing[] = 'CODEGUARDIAN_SENTRY_PROJECT'; }
        return $missing;
    }

    /**
     * Fetch unresolved issues, newest-first, capped at $limit.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unresolvedIssues(int $limit = 10): array
    {
        $query = 'is:unresolved';
        if ($this->environment !== '') {
            $query .= " environment:{$this->environment}";
        }

        $path = sprintf(
            '/api/0/projects/%s/%s/issues/?query=%s&statsPeriod=14d&limit=%d',
            rawurlencode($this->organization),
            rawurlencode($this->project),
            rawurlencode($query),
            max(1, min($limit, 100)),
        );

        $data = $this->get($path);
        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * Fetch the latest event (with full stack trace) for an issue.
     *
     * @return array<string,mixed>|null
     */
    public function latestEvent(string $issueId): ?array
    {
        $data = $this->get(sprintf('/api/0/issues/%s/events/latest/', rawurlencode($issueId)));
        return is_array($data) ? $data : null;
    }

    /**
     * Fetch a single issue by id (used when acting on one specific issue, e.g.
     * from a Slack "Fix" button).
     *
     * @return array<string,mixed>|null
     */
    public function issue(string $issueId): ?array
    {
        $data = $this->get(sprintf('/api/0/issues/%s/', rawurlencode($issueId)));
        return (is_array($data) && $data !== []) ? $data : null;
    }

    /** Mark an issue resolved in Sentry. Returns true on success. */
    public function resolveIssue(string $issueId): bool
    {
        return $this->put(
            sprintf('/api/0/issues/%s/', rawurlencode($issueId)),
            ['status' => 'resolved']
        );
    }

    // ─── Pure helpers (no network) ──────────────────────────────────────────

    /**
     * Compact, human-readable summary of an issue for logs/Slack.
     *
     * @param  array<string,mixed> $issue
     * @return array{id:string,title:string,culprit:string,count:int,level:string,permalink:string}
     */
    public static function summariseIssue(array $issue): array
    {
        return [
            'id'        => (string) ($issue['id'] ?? ''),
            'title'     => trim((string) ($issue['title'] ?? $issue['metadata']['type'] ?? 'Unknown issue')),
            'culprit'   => (string) ($issue['culprit'] ?? ''),
            'count'     => (int) ($issue['count'] ?? 0),
            'level'     => (string) ($issue['level'] ?? 'error'),
            'permalink' => (string) ($issue['permalink'] ?? ''),
        ];
    }

    /**
     * Extract the exception type + message from an event.
     *
     * @param  array<string,mixed> $event
     * @return array{type:string,value:string}
     */
    public static function exceptionOf(array $event): array
    {
        $entries = $event['entries'] ?? [];
        // Modern events: entries[] with type "exception".
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (($entry['type'] ?? null) === 'exception') {
                $values = $entry['data']['values'] ?? [];
                $last   = is_array($values) && $values !== [] ? end($values) : null;
                if (is_array($last)) {
                    return [
                        'type'  => (string) ($last['type'] ?? 'Error'),
                        'value' => (string) ($last['value'] ?? ''),
                    ];
                }
            }
        }
        // Fallback: top-level exception shape.
        $values = $event['exception']['values'] ?? [];
        $last   = is_array($values) && $values !== [] ? end($values) : null;
        if (is_array($last)) {
            return [
                'type'  => (string) ($last['type'] ?? 'Error'),
                'value' => (string) ($last['value'] ?? ''),
            ];
        }

        return [
            'type'  => (string) ($event['metadata']['type'] ?? 'Error'),
            'value' => (string) ($event['metadata']['value'] ?? ($event['title'] ?? '')),
        ];
    }

    /**
     * The frame where the error actually occurred: the LAST in-app frame in the
     * crashing exception's stack trace (Sentry orders frames oldest → newest, so
     * the throw site is last). Falls back to the last frame of any kind.
     *
     * @param  array<string,mixed> $event
     * @return array{filename:string,function:string,lineno:int,context:array<int,array{0:int,1:string}>}|null
     */
    public static function culpritFrame(array $event): ?array
    {
        $frames = self::extractFrames($event);
        if ($frames === []) {
            return null;
        }

        $chosen = null;
        foreach ($frames as $frame) {
            if (! is_array($frame)) {
                continue;
            }
            if (($frame['in_app'] ?? false) === true) {
                $chosen = $frame; // keep updating → ends on the last in-app frame
            }
        }
        // No in-app frame flagged? Use the very last frame (the throw site).
        if ($chosen === null) {
            $chosen = end($frames) ?: null;
        }
        if (! is_array($chosen)) {
            return null;
        }

        $context = [];
        foreach ($chosen['context'] ?? [] as $pair) {
            if (is_array($pair) && count($pair) >= 2) {
                $context[] = [(int) $pair[0], (string) $pair[1]];
            }
        }

        return [
            'filename' => (string) ($chosen['filename'] ?? $chosen['absPath'] ?? $chosen['abs_path'] ?? ''),
            'function' => (string) ($chosen['function'] ?? ''),
            'lineno'   => (int) ($chosen['lineNo'] ?? $chosen['lineno'] ?? 0),
            'context'  => $context,
        ];
    }

    /**
     * Pull the stack-trace frames out of an event, coping with the several JSON
     * shapes Sentry uses across SDK/API versions.
     *
     * @param  array<string,mixed> $event
     * @return array<int,mixed>
     */
    private static function extractFrames(array $event): array
    {
        $entries = $event['entries'] ?? [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (($entry['type'] ?? null) === 'exception') {
                $values = $entry['data']['values'] ?? [];
                $last   = is_array($values) && $values !== [] ? end($values) : null;
                $frames = $last['stacktrace']['frames'] ?? null;
                if (is_array($frames)) {
                    return $frames;
                }
            }
        }

        $values = $event['exception']['values'] ?? [];
        $last   = is_array($values) && $values !== [] ? end($values) : null;
        if (is_array($last) && isset($last['stacktrace']['frames']) && is_array($last['stacktrace']['frames'])) {
            return $last['stacktrace']['frames'];
        }

        if (isset($event['stacktrace']['frames']) && is_array($event['stacktrace']['frames'])) {
            return $event['stacktrace']['frames'];
        }

        return [];
    }

    /**
     * Map a Sentry frame path — which is usually the absolute path inside the
     * server/container (e.g. /var/www/html/app/Http/Controllers/Foo.php) — to a
     * path relative to this project's root, verifying the file actually exists.
     * Returns null when it cannot be confidently located here.
     */
    public static function resolveLocalPath(string $sentryPath, string $projectRoot): ?string
    {
        $sentryPath = trim($sentryPath);
        if ($sentryPath === '') {
            return null;
        }

        $norm = ltrim(str_replace('\\', '/', $sentryPath));
        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');

        // 1) Absolute path that lives under the project root.
        if (str_starts_with($norm, $root . '/')) {
            $rel = ltrim(substr($norm, strlen($root)), '/');
            if ($rel !== '' && is_file($root . '/' . $rel)) {
                return $rel;
            }
        }

        // 2) Anchor on a known source root anywhere in the path, then verify.
        foreach (self::SOURCE_ANCHORS as $anchor) {
            $pos = strpos($norm, '/' . $anchor);
            $rel = null;
            if (str_starts_with($norm, $anchor)) {
                $rel = $norm;
            } elseif ($pos !== false) {
                $rel = substr($norm, $pos + 1);
            }
            if ($rel !== null && is_file($root . '/' . $rel)) {
                return $rel;
            }
        }

        // 3) Already a valid relative path.
        if (is_file($root . '/' . $norm)) {
            return $norm;
        }

        return null;
    }

    // ─── Network (isolated) ─────────────────────────────────────────────────

    /** @return array<mixed>|null */
    private function get(string $path)
    {
        try {
            $response = $this->http->get($this->baseUrl . $path, [
                'headers' => ['Authorization' => 'Bearer ' . $this->token],
            ]);
            $decoded = json_decode((string) $response->getBody(), true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $body */
    private function put(string $path, array $body): bool
    {
        try {
            $response = $this->http->put($this->baseUrl . $path, [
                'headers' => ['Authorization' => 'Bearer ' . $this->token],
                'json'    => $body,
            ]);
            $code = $response->getStatusCode();
            return $code >= 200 && $code < 300;
        } catch (\Throwable) {
            return false;
        }
    }
}
