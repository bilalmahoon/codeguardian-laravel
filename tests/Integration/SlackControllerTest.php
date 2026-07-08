<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use CodeGuardian\Laravel\Support\RunStore;
use CodeGuardian\Laravel\Support\SlackSignature;
use Orchestra\Testbench\TestCase;

/**
 * Verifies the Slack App endpoints: signature enforcement, slash-command
 * routing, and the interactive "Fix" button. RunStore is faked so no real
 * background process is spawned.
 */
class SlackControllerTest extends TestCase
{
    private string $secret = 'test-signing-secret';

    protected function getPackageProviders($app): array
    {
        return [\CodeGuardian\Laravel\CodeGuardianServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('codeguardian.slack.enabled', true);
        $app['config']->set('codeguardian.slack.signing_secret', $this->secret);
        $app['config']->set('codeguardian.dashboard.path', 'codeguardian');
    }

    /** Replace RunStore with a double that records launches instead of spawning. */
    private function fakeRunStore(): object
    {
        $fake = new class(sys_get_temp_dir(), sys_get_temp_dir()) extends RunStore {
            public array $launches = [];
            public function start(string $type, string $artisan, array $options, string $label): string
            {
                $this->launches[] = compact('type', 'artisan', 'options', 'label');
                return 'run-fake-id';
            }
        };
        $this->app->instance(RunStore::class, $fake);

        return $fake;
    }

    /** @param array<string,string> $data */
    private function signedPost(string $uri, array $data)
    {
        $body = http_build_query($data);
        $ts   = time();
        $sig  = SlackSignature::compute($this->secret, $ts, $body);

        return $this->call('POST', $uri, $data, [], [], [
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => (string) $ts,
            'HTTP_X_SLACK_SIGNATURE'         => $sig,
            'CONTENT_TYPE'                   => 'application/x-www-form-urlencoded',
        ], $body);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->post('/codeguardian/slack/command', ['text' => 'sentry'])
            ->assertStatus(403);
    }

    public function test_disabled_returns_404_at_runtime(): void
    {
        config()->set('codeguardian.slack.enabled', false);
        $this->signedPost('/codeguardian/slack/command', ['text' => 'sentry'])
            ->assertStatus(404);
    }

    public function test_valid_slash_command_launches_and_acks(): void
    {
        $fake = $this->fakeRunStore();

        $res = $this->signedPost('/codeguardian/slack/command', [
            'command' => '/codeguardian', 'text' => 'sentry-fix', 'user_name' => 'bilal',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('response_type', 'ephemeral');
        $this->assertStringContainsString('sentry-fix', $res->json('text'));
        $this->assertCount(1, $fake->launches);
        $this->assertSame('codeguardian:sentry', $fake->launches[0]['artisan']);
        $this->assertTrue($fake->launches[0]['options']['fix']);
        $this->assertTrue($fake->launches[0]['options']['apply']);
    }

    public function test_help_text_for_empty_command(): void
    {
        $res = $this->signedPost('/codeguardian/slack/command', ['text' => '']);
        $res->assertStatus(200);
        $this->assertStringContainsString('Usage', $res->json('text'));
    }

    public function test_unknown_command_is_reported(): void
    {
        $res = $this->signedPost('/codeguardian/slack/command', ['text' => 'launch-missiles']);
        $res->assertStatus(200);
        $this->assertStringContainsString('Unknown command', $res->json('text'));
    }

    public function test_interactive_fix_button_launches_single_issue_fix(): void
    {
        $fake = $this->fakeRunStore();

        $payload = json_encode([
            'user'    => ['username' => 'bilal'],
            'actions' => [['action_id' => 'cg_sentry_fix', 'value' => 'ISSUE-42']],
        ]);

        $res = $this->signedPost('/codeguardian/slack/interact', ['payload' => $payload]);

        $res->assertStatus(200);
        $this->assertStringContainsString('ISSUE-42', $res->json('text'));
        $this->assertCount(1, $fake->launches);
        $this->assertSame('ISSUE-42', $fake->launches[0]['options']['issue']);
        $this->assertTrue($fake->launches[0]['options']['resolve']);
    }
}
