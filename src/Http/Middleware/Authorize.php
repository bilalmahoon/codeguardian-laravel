<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the CodeGuardian dashboard.
 *
 * Resolution order:
 *   1. If a 'viewCodeGuardian' Gate is defined, it is authoritative.
 *   2. Otherwise, when restrict_to_local is true (default), only the local
 *      environment may access the dashboard.
 *   3. Otherwise, access is allowed (operator explicitly opened it).
 *
 * This keeps the tool safe-by-default while letting teams expose it behind
 * their own auth by defining the gate.
 */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('codeguardian.dashboard.enabled', true)) {
            abort(404);
        }

        if (! $this->allowed($request)) {
            abort(403, 'CodeGuardian dashboard access denied. Define a "viewCodeGuardian" '
                . 'Gate or set codeguardian.dashboard.restrict_to_local = false to allow access.');
        }

        return $next($request);
    }

    private function allowed(Request $request): bool
    {
        if (Gate::has('viewCodeGuardian')) {
            return Gate::check('viewCodeGuardian', [$request->user()]);
        }

        if (config('codeguardian.dashboard.restrict_to_local', true)) {
            return app()->environment('local');
        }

        return true;
    }
}
