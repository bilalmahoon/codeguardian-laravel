<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the CodeGuardian dashboard.
 *
 * Resolution order (first match wins):
 *   1. A 'viewCodeGuardian' Gate, if defined, is authoritative.
 *   2. require_login: any AUTHENTICATED user may access; guests are redirected
 *      to the app's login page (or get 403 if the app has no login route).
 *      Use this to expose the dashboard on a shared/staging server behind the
 *      app's own auth.
 *   3. restrict_to_local (default): only the local environment may access.
 *   4. Otherwise access is allowed (operator explicitly opened it).
 *
 * This keeps the tool safe-by-default while letting teams expose it behind
 * their own auth without writing any code.
 */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('codeguardian.dashboard.enabled', true)) {
            abort(404);
        }

        // 1) Explicit Gate is always authoritative when present.
        if (Gate::has('viewCodeGuardian')) {
            return Gate::check('viewCodeGuardian', [$request->user()])
                ? $next($request)
                : $this->deny($request);
        }

        // 2) Login-protected dashboard: allow any authenticated user.
        if (config('codeguardian.dashboard.require_login', false)) {
            return $request->user()
                ? $next($request)
                : $this->deny($request);
        }

        // 3) Local-only (safe default).
        if (config('codeguardian.dashboard.restrict_to_local', true)) {
            return app()->environment('local')
                ? $next($request)
                : $this->deny($request);
        }

        // 4) Explicitly opened.
        return $next($request);
    }

    /**
     * Deny access: send guests to the login page when the app has one,
     * otherwise return a 403 with a helpful hint.
     */
    private function deny(Request $request): Response
    {
        if (! $request->user() && ! $request->expectsJson() && Route::has('login')) {
            return redirect()->guest(route('login'));
        }

        abort(403, 'CodeGuardian dashboard access denied. Log in, define a '
            . '"viewCodeGuardian" Gate, or adjust codeguardian.dashboard settings '
            . '(require_login / restrict_to_local).');
    }
}
