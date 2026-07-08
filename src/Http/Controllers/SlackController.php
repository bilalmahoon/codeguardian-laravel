<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Http\Controllers;

use CodeGuardian\Laravel\Support\RunStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Drives CodeGuardian FROM a Slack channel (requests are already verified by the
 * VerifySlackSignature middleware):
 *
 *   • Slash command   /codeguardian <sub>     → launches a background run.
 *   • Interactivity    "Fix" button on a issue → auto-fixes that Sentry issue.
 *
 * Slack requires a response within 3s, so we launch the work as a detached
 * background run (via RunStore, same as the dashboard) and immediately ack. The
 * run itself posts its result back to the channel through the Slack webhook.
 */
class SlackController
{
    /**
     * Whitelisted slash sub-commands → [type, artisan, options]. Never build a
     * command from raw Slack text; only these fixed recipes can run.
     *
     * @return array<string,array{0:string,1:string,2:array<string,string|bool>}>
     */
    private function recipes(): array
    {
        return [
            'sentry'      => ['sentry',      'codeguardian:sentry',      ['slack' => true]],
            'sentry-fix'  => ['sentry',      'codeguardian:sentry',      ['fix' => true, 'apply' => true, 'with-tests' => true, 'resolve' => true, 'slack' => true]],
            'analyze'     => ['analyze',     'codeguardian:analyze',     ['format' => 'json']],
            'security'    => ['security',    'codeguardian:security',    []],
            'performance' => ['performance', 'codeguardian:performance', []],
        ];
    }

    /** Handle a Slack slash command (application/x-www-form-urlencoded). */
    public function command(Request $request): JsonResponse
    {
        $text = trim((string) $request->input('text', ''));
        $key  = strtolower(preg_replace('/\s+/', '-', $text) ?: '');

        if ($key === '' || $key === 'help') {
            return $this->ephemeral($this->helpText());
        }

        $recipes = $this->recipes();
        if (! isset($recipes[$key])) {
            return $this->ephemeral("Unknown command `{$text}`.\n" . $this->helpText());
        }

        [$type, $artisan, $options] = $recipes[$key];
        $user = (string) $request->input('user_name', 'someone');
        $id   = $this->launch($type, $artisan, $options, "Slack /{$key} by {$user}");

        return $this->ephemeral(
            "🛡️ *CodeGuardian* started `{$key}` (run `{$id}`). I'll post the result to this channel when it finishes."
        );
    }

    /** Handle a Slack interactive action (button click). */
    public function interact(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->input('payload', ''), true);
        $action  = is_array($payload) ? ($payload['actions'][0] ?? null) : null;

        if (! is_array($action)) {
            return $this->ephemeral('No action received.');
        }

        $actionId = (string) ($action['action_id'] ?? '');
        $value    = (string) ($action['value'] ?? '');
        $user     = (string) ($payload['user']['username'] ?? $payload['user']['name'] ?? 'someone');

        if ($actionId === 'cg_sentry_fix' && $value !== '') {
            $id = $this->launch('sentry', 'codeguardian:sentry', [
                'issue'      => $value,
                'fix'        => true,
                'apply'      => true,
                'with-tests' => true,
                'resolve'    => true,
                'slack'      => true,
            ], "Slack fix of issue {$value} by {$user}");

            return $this->ephemeral("🛠️ Fixing Sentry issue `{$value}` (run `{$id}`). Result will land here shortly.");
        }

        return $this->ephemeral('Unsupported action.');
    }

    /**
     * @param  array<string,string|bool> $options
     */
    private function launch(string $type, string $artisan, array $options, string $label): string
    {
        try {
            return app(RunStore::class)->start($type, $artisan, $options, $label);
        } catch (\Throwable $e) {
            return 'error:' . substr($e->getMessage(), 0, 40);
        }
    }

    private function helpText(): string
    {
        return "Usage: `/codeguardian <command>`\n"
            . "• `sentry` — triage production issues\n"
            . "• `sentry-fix` — auto-fix + verify + resolve production issues\n"
            . "• `analyze` — full code analysis\n"
            . "• `security` — security scan\n"
            . "• `performance` — performance scan";
    }

    private function ephemeral(string $text): JsonResponse
    {
        return response()->json(['response_type' => 'ephemeral', 'text' => $text]);
    }
}
