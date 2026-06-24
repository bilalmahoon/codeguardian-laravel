<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration\Fixtures\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Simulates the project's RouteServiceProvider.
 * This file must NEVER appear in the API scope for --api=v1/auth/login
 * because it is a framework provider, not a request handler.
 */
class AppRouteServiceProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        // intentionally empty — routes are registered directly in the test
    }
}
