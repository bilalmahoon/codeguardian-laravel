<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Unrelated controller — must NEVER appear in scope for --api=v1/auth/login.
 * It is not a dependency of ApiAuthController and not the route handler.
 */
class RegisterController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        return response()->json(['success' => true]);
    }
}
