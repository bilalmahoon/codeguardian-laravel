<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use CodeGuardian\Laravel\Support\RunStore;
use CodeGuardian\Laravel\Support\SentryClient;
use CodeGuardian\Laravel\Support\SlackService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase;

/**
 * The in-dashboard Sentry & Slack panels: navigation, setup states, filtered
 * listings, and the removal of the AI-provider label. External APIs are faked.
 */
class IntegrationPanelsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\CodeGuardian\Laravel\CodeGuardianServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The dashboard routes run through the `web` middleware group, which
        // encrypts cookies → needs an app key.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // Open the dashboard for test requests (no gate / login / local-only).
        $app['config']->set('codeguardian.dashboard.restrict_to_local', false);
        $app['config']->set('codeguardian.dashboard.require_login', false);
    }

    private function fakeSentry(array $responses): void
    {
        $mock   = new MockHandler(array_map(fn($b) => new Response(200, [], json_encode($b)), $responses));
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $this->app->bind(SentryClient::class, fn() => new SentryClient('tok', 'org', 'proj', 'https://sentry.io', '', $client));
    }

    private function fakeSentryUnconfigured(): void
    {
        $this->app->bind(SentryClient::class, fn() => new SentryClient('', '', '', 'https://sentry.io', ''));
    }

    private function fakeSlack(array $historyResponse, bool $configured = true): void
    {
        $mock   = new MockHandler([new Response(200, [], json_encode($historyResponse))]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $channels = $configured ? [['id' => 'C1', 'label' => 'alerts']] : [];
        $this->app->bind(SlackService::class, fn() => new SlackService($configured ? 'xoxb' : '', $channels, $client));
    }

    // ─── Navigation / AI removal ────────────────────────────────────────────

    public function test_nav_shows_integrations_and_hides_ai_label(): void
    {
        $this->fakeSentryUnconfigured();

        $html = $this->get('/codeguardian/sentry')->assertStatus(200)->getContent();

        $this->assertStringContainsString('Sentry', $html);
        $this->assertStringContainsString('Slack', $html);
        // The AI provider label must be gone.
        $this->assertStringNotContainsString('🤖 AI', $html);
        // Future-ready plugin nav is present.
        $this->assertStringContainsString('Soon', $html);
    }

    // ─── Sentry panel ───────────────────────────────────────────────────────

    public function test_sentry_shows_setup_when_unconfigured(): void
    {
        $this->fakeSentryUnconfigured();

        $this->get('/codeguardian/sentry')
            ->assertStatus(200)
            ->assertSee('Connect Sentry')
            ->assertSee('CODEGUARDIAN_SENTRY_TOKEN');
    }

    public function test_sentry_lists_issues_with_filters(): void
    {
        $issues = [[
            'id' => '42', 'title' => 'TypeError: bad arg', 'culprit' => 'App\\X::y',
            'count' => 12, 'userCount' => 3, 'level' => 'error', 'status' => 'unresolved',
            'lastSeen' => '2026-07-01T00:00:00Z', 'firstSeen' => '2026-06-01T00:00:00Z', 'shortId' => 'PROJ-1',
        ]];
        $projects     = [['slug' => 'proj', 'name' => 'Proj']];
        $environments = [['name' => 'production']];

        // index() calls listIssues → projects → environments in that order.
        $this->fakeSentry([$issues, $projects, $environments]);

        $this->get('/codeguardian/sentry?status=unresolved&level=error')
            ->assertStatus(200)
            ->assertSee('TypeError: bad arg')
            ->assertSee('PROJ-1', false);
    }

    public function test_sentry_detail_resolves_and_renders(): void
    {
        $issue = [
            'id' => '42', 'title' => 'TypeError: bad arg', 'culprit' => 'App\\X::y',
            'count' => 12, 'userCount' => 3, 'level' => 'error', 'status' => 'unresolved',
            'permalink' => 'https://sentry.io/x/42/',
        ];
        $event = [
            'entries' => [['type' => 'exception', 'data' => ['values' => [[
                'type' => 'TypeError', 'value' => 'boom',
                'stacktrace' => ['frames' => [
                    ['filename' => '/var/www/app/Ghost.php', 'lineNo' => 9, 'in_app' => true],
                ]],
            ]]]]],
        ];

        // show() calls issue() then latestEvent().
        $this->fakeSentry([$issue, $event]);

        $this->get('/codeguardian/sentry/42')
            ->assertStatus(200)
            ->assertSee('TypeError')
            ->assertSee('boom')
            ->assertSee('codeguardian:sentry --issue=42', false);
    }

    // ─── Slack panel ──────────────────────────────────────────────────────

    public function test_slack_shows_setup_when_unconfigured(): void
    {
        $this->fakeSlack([], false);

        $this->get('/codeguardian/slack')
            ->assertStatus(200)
            ->assertSee('Connect Slack')
            ->assertSee('CODEGUARDIAN_SLACK_BOT_TOKEN');
    }

    public function test_slack_lists_channel_messages(): void
    {
        $this->fakeSlack([
            'ok' => true,
            'messages' => [
                ['type' => 'message', 'user' => 'U2', 'text' => 'Production is on fire', 'ts' => '1700000100.2'],
            ],
        ]);

        $this->get('/codeguardian/slack')
            ->assertStatus(200)
            ->assertSee('Production is on fire')
            ->assertSee('alerts')
            ->assertSee('/codeguardian/slack/C1/1700000100.2', false); // clickable → detail
    }

    public function test_slack_message_detail_page(): void
    {
        // history (single message via latest+inclusive) then chat.getPermalink.
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'ok' => true,
                'messages' => [['type' => 'message', 'user' => 'U2', 'text' => 'Payment webhook 500s', 'ts' => '1700000100.2']],
            ])),
            new Response(200, [], json_encode(['ok' => true, 'permalink' => 'https://slack.com/archives/C1/p1700000100'])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $this->app->bind(SlackService::class, fn() => new SlackService('xoxb', [['id' => 'C1', 'label' => 'alerts']], $client));

        $this->get('/codeguardian/slack/C1/1700000100.2')
            ->assertStatus(200)
            ->assertSee('Payment webhook 500s')
            ->assertSee('Open in Slack');
    }

    public function test_slack_detail_rejects_unknown_channel(): void
    {
        $this->fakeSlack(['ok' => true, 'messages' => []]);

        $this->get('/codeguardian/slack/CHACKER/1700000100.2')->assertStatus(404);
    }

    // ─── Sentry auto-fix action ─────────────────────────────────────────────

    public function test_sentry_fix_launches_run_and_redirects(): void
    {
        $this->fakeSentry([]); // configured client; fix() makes no network calls
        config()->set('codeguardian.provider', 'claude');
        config()->set('codeguardian.claude.key', 'sk-test');

        $fake = new class(sys_get_temp_dir(), sys_get_temp_dir()) extends RunStore {
            public array $launches = [];
            public function start(string $type, string $artisan, array $options, string $label): string
            {
                $this->launches[] = compact('type', 'artisan', 'options', 'label');
                return 'run-x';
            }
        };
        $this->app->instance(RunStore::class, $fake);

        $this->post('/codeguardian/sentry/42/fix')
            ->assertRedirect('/codeguardian/runs/run-x');

        $this->assertCount(1, $fake->launches);
        $this->assertSame('codeguardian:sentry', $fake->launches[0]['artisan']);
        $this->assertSame('42', $fake->launches[0]['options']['issue']);
        $this->assertTrue($fake->launches[0]['options']['resolve']);
    }

    public function test_sentry_fix_without_ai_key_redirects_with_error(): void
    {
        $this->fakeSentry([]);
        config()->set('codeguardian.provider', 'claude');
        config()->set('codeguardian.claude.key', null);
        config()->set('codeguardian.openai.key', null);
        config()->set('codeguardian.gemini.key', null);

        $this->post('/codeguardian/sentry/42/fix')
            ->assertRedirect('/codeguardian/sentry/42');
    }
}
