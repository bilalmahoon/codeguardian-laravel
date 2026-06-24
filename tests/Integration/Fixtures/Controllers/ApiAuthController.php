<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration\Fixtures\Controllers;

use CodeGuardian\Laravel\Tests\Integration\Fixtures\Services\AuthService;
use CodeGuardian\Laravel\Tests\Integration\Fixtures\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Simulates a real "login API" controller that injects two services.
 * Used to verify DependencyTracer finds both AuthService and TokenService.
 */
class ApiAuthController extends Controller
{
    public function __construct(
        private AuthService  $authService,
        private TokenService $tokenService,
    ) {}

    public function authenticateUser(Request $request): JsonResponse
    {
        return response()->json(['token' => $this->tokenService->generate('user_id')]);
    }

    public function logout(Request $request): JsonResponse
    {
        return response()->json(['success' => true]);
    }
}
