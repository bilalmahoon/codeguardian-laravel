<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Http\Controllers\Integrations;

use CodeGuardian\Laravel\Support\SlackService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Slack panel — follow the development / incident channels your team uses,
 * inside CodeGuardian. Read-only today; future actions (reply, resolve, assign)
 * plug in as new methods + routes without reworking this view.
 */
class SlackController
{
    public function __construct(private readonly SlackService $slack)
    {
    }

    public function index(Request $request): Response
    {
        if (! $this->slack->configured()) {
            return response()->view('codeguardian::integrations.slack.index', [
                'configured' => false,
                'missing'    => $this->slack->missingConfig(),
                'channels'   => [],
                'messages'   => [],
            ]);
        }

        $channels = $this->slack->channels();
        $current  = (string) $request->query('channel', '') ?: (string) $this->slack->defaultChannel();

        // Only browse channels the operator has whitelisted.
        $allowed = array_column($channels, 'id');
        if (! in_array($current, $allowed, true)) {
            $current = (string) $this->slack->defaultChannel();
        }

        return response()->view('codeguardian::integrations.slack.index', [
            'configured'    => true,
            'channels'      => $channels,
            'current'       => $current,
            'currentLabel'  => $this->slack->channelLabel($current),
            'messages'      => $this->slack->messages($current, 40),
        ]);
    }

    public function show(string $channel, string $ts): Response
    {
        if (! $this->slack->configured()) {
            abort(404);
        }

        // Only channels the operator whitelisted may be browsed.
        if (! in_array($channel, array_column($this->slack->channels(), 'id'), true)) {
            abort(404);
        }

        $message = $this->slack->message($channel, $ts);
        if ($message === null) {
            abort(404, 'Message not found.');
        }

        return response()->view('codeguardian::integrations.slack.show', [
            'configured'   => true,
            'channelId'    => $channel,
            'channelLabel' => $this->slack->channelLabel($channel),
            'message'      => $message,
            'permalink'    => $this->slack->permalink($channel, $ts),
        ]);
    }
}
