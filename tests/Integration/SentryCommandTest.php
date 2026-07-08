<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use CodeGuardian\Laravel\Support\SentryClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase;

/**
 * Boot-level tests for `codeguardian:sentry`. Network is faked with a Guzzle
 * MockHandler injected via the container binding, so the full pipeline
 * (fetch → event → stack trace → file resolution → report) runs without Sentry.
 */
class SentryCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\CodeGuardian\Laravel\CodeGuardianServiceProvider::class];
    }

    /** Bind a SentryClient whose HTTP layer replays the given canned responses. */
    private function fakeSentry(array $responses): void
    {
        $mock   = new MockHandler(array_map(
            fn($body) => new Response(200, [], json_encode($body)),
            $responses
        ));
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $this->app->bind(SentryClient::class, fn() => new SentryClient(
            'token', 'org', 'project', 'https://sentry.io', '', $client
        ));
    }

    public function test_fails_cleanly_when_not_configured(): void
    {
        // Default binding reads empty config → not configured.
        $this->artisan('codeguardian:sentry')
            ->expectsOutputToContain('Sentry is not configured')
            ->assertExitCode(1);
    }

    public function test_fix_without_ai_key_fails_cleanly(): void
    {
        $this->fakeSentry([]); // configured, but no issues needed — AI check runs first
        config()->set('codeguardian.openai.key', null);
        config()->set('codeguardian.claude.key', null);
        config()->set('codeguardian.gemini.key', null);

        $this->artisan('codeguardian:sentry --fix')
            ->expectsOutputToContain('--fix needs an AI provider key')
            ->assertExitCode(1);
    }

    public function test_no_issues_is_success(): void
    {
        $this->fakeSentry([[]]); // issues endpoint returns an empty list

        $this->artisan('codeguardian:sentry')
            ->expectsOutputToContain('No unresolved issues')
            ->assertExitCode(0);
    }

    public function test_triage_reports_issue_whose_file_is_absent(): void
    {
        $issue = [
            'id' => '9001', 'title' => 'TypeError: bad argument', 'culprit' => 'App\\X::y',
            'count' => 5, 'level' => 'error', 'permalink' => 'https://sentry.io/x/9001/',
        ];
        $event = [
            'entries' => [
                ['type' => 'exception', 'data' => ['values' => [[
                    'type' => 'TypeError', 'value' => 'boom',
                    'stacktrace' => ['frames' => [
                        ['filename' => '/var/www/app/Http/Controllers/GhostController.php', 'lineNo' => 10, 'in_app' => true],
                    ]],
                ]]]],
            ],
        ];

        // issues list, then latest event.
        $this->fakeSentry([[$issue], $event]);

        $this->artisan('codeguardian:sentry --limit=1')
            ->expectsOutputToContain('TypeError: bad argument')
            ->assertExitCode(1); // "no-file" needs human attention → non-zero
    }

    public function test_watch_processes_new_issue_once_and_stops(): void
    {
        // Fresh state file so this issue counts as "new".
        @unlink(storage_path('codeguardian/sentry/state.json'));

        $id    = 'WATCH-' . uniqid();
        $issue = [
            'id' => $id, 'title' => 'RuntimeException: watched', 'culprit' => 'App\\X::y',
            'count' => 2, 'level' => 'error', 'permalink' => 'https://sentry.io/x/1/',
        ];
        $event = [
            'entries' => [
                ['type' => 'exception', 'data' => ['values' => [[
                    'type' => 'RuntimeException', 'value' => 'boom',
                    'stacktrace' => ['frames' => [
                        ['filename' => '/var/www/app/Ghost.php', 'lineNo' => 3, 'in_app' => true],
                    ]],
                ]]]],
            ],
        ];

        $this->fakeSentry([[$issue], $event]);

        // One poll then stop; watch always exits SUCCESS.
        $this->artisan('codeguardian:sentry --watch --max-iterations=1 --interval=5 --limit=1')
            ->expectsOutputToContain('Watching Sentry')
            ->expectsOutputToContain('RuntimeException: watched')
            ->assertExitCode(0);

        @unlink(storage_path('codeguardian/sentry/state.json'));
    }

    public function test_single_issue_not_found_fails(): void
    {
        // issue() endpoint returns empty object → treated as not found.
        $this->fakeSentry([[]]);

        $this->artisan('codeguardian:sentry --issue=NOPE')
            ->assertExitCode(1);
    }
}
