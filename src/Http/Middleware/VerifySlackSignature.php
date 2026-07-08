<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Http\Middleware;

use Closure;
use CodeGuardian\Laravel\Support\SlackSignature;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any request to the Slack endpoints that is not a validly-signed,
 * recent request from Slack. Guards the command/interactivity routes in place
 * of session auth (Slack cannot present a CSRF token or a logged-in user).
 */
class VerifySlackSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('codeguardian.slack.enabled', false)) {
            abort(404);
        }

        $secret = (string) config('codeguardian.slack.signing_secret', '');
        if ($secret === '') {
            abort(403, 'CodeGuardian Slack signing secret is not configured.');
        }

        $ok = SlackSignature::verify(
            $secret,
            (string) $request->header('X-Slack-Request-Timestamp', ''),
            (string) $request->header('X-Slack-Signature', ''),
            $request->getContent(),
            time()
        );

        if (! $ok) {
            abort(403, 'Invalid Slack signature.');
        }

        return $next($request);
    }
}
