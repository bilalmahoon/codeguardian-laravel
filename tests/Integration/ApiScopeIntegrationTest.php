<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use CodeGuardian\Laravel\Support\CodeScanner;
use CodeGuardian\Laravel\Support\DependencyTracer;
use CodeGuardian\Laravel\Support\RouteResolver;
use CodeGuardian\Laravel\Tests\Integration\Fixtures\Controllers\ApiAuthController;
use CodeGuardian\Laravel\Tests\Integration\Fixtures\Controllers\RegisterController;
use CodeGuardian\Laravel\Tests\Integration\Fixtures\Repositories\UserRepository;
use CodeGuardian\Laravel\Tests\Integration\Fixtures\Services\AuthService;
use CodeGuardian\Laravel\Tests\Integration\Fixtures\Services\TokenService;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

/**
 * Integration tests for the API scope builder.
 *
 * These tests use a real Laravel application (via Orchestra Testbench) with
 * real route definitions, real controller classes, and real service/repository
 * classes. This is the only way to guarantee the Router + Reflection chain
 * works exactly as expected before shipping to any project.
 *
 * Route under test:
 *   POST /api/v1/auth/login → ApiAuthController@authenticateUser
 *
 * Expected scope for --api=v1/auth/login:
 *   ✓ ApiAuthController.php         (direct route handler)
 *   ✓ AuthService.php               (injected in controller constructor, hop 1)
 *   ✓ TokenService.php              (injected in controller constructor, hop 1)
 *   ✓ UserRepository.php            (injected in AuthService constructor, hop 2)
 *
 * Must NOT appear in scope:
 *   ✗ RegisterController.php        (different route, not a dependency)
 *   ✗ AppRouteServiceProvider.php   (framework provider, not a request handler)
 */
class ApiScopeIntegrationTest extends TestCase
{
    // ─── Route registration ───────────────────────────────────────────────────

    /**
     * Register the fixture routes in the test application's router.
     * This is called by Orchestra Testbench after the application boots.
     */
    protected function defineRoutes($router): void
    {
        $router->post('api/v1/auth/login',    [ApiAuthController::class, 'authenticateUser']);
        $router->post('api/v1/auth/logout',   [ApiAuthController::class, 'logout']);
        $router->post('api/v1/auth/register', [RegisterController::class, 'register']);
    }

    // ─── RouteResolver ────────────────────────────────────────────────────────

    /** @test */
    public function test_route_resolver_finds_exact_controller_for_login(): void
    {
        $resolver = new RouteResolver($this->app->basePath());
        $resolved = $resolver->resolve('v1/auth/login');

        $this->assertNotEmpty($resolved, 'RouteResolver must find at least one route for v1/auth/login');
        $this->assertCount(1, $resolved, 'Exactly one route should match v1/auth/login (POST only, after dedup)');

        $route = $resolved[0];
        $this->assertSame(ApiAuthController::class, $route['class']);
        $this->assertSame('authenticateUser', $route['method']);
    }

    /** @test */
    public function test_route_resolver_does_not_return_register_controller_for_login(): void
    {
        $resolver = new RouteResolver($this->app->basePath());
        $resolved = $resolver->resolve('v1/auth/login');

        $classes = array_column($resolved, 'class');
        $this->assertNotContains(RegisterController::class, $classes,
            'RegisterController must NOT appear in scope for v1/auth/login'
        );
    }

    /** @test */
    public function test_route_resolver_handles_different_uri_filters(): void
    {
        $resolver = new RouteResolver($this->app->basePath());

        // logout should resolve to ApiAuthController@logout
        $logoutResolved = $resolver->resolve('v1/auth/logout');
        $this->assertNotEmpty($logoutResolved);
        $this->assertSame('logout', $logoutResolved[0]['method']);

        // register should resolve to RegisterController@register
        $registerResolved = $resolver->resolve('v1/auth/register');
        $this->assertNotEmpty($registerResolved);
        $this->assertSame(RegisterController::class, $registerResolved[0]['class']);
    }

    /** @test */
    public function test_route_resolver_returns_empty_for_nonexistent_route(): void
    {
        $resolver = new RouteResolver($this->app->basePath());
        $resolved = $resolver->resolve('v1/auth/does-not-exist');

        $this->assertIsArray($resolved);
        $this->assertEmpty($resolved, 'Non-existent route must return empty array');
    }

    // ─── DependencyTracer ─────────────────────────────────────────────────────

    /** @test */
    public function test_dependency_tracer_finds_all_three_layers_for_login_controller(): void
    {
        // The project root for this test is the fixtures directory
        $fixturesRoot = realpath(__DIR__ . '/Fixtures');
        $tracer       = new DependencyTracer($fixturesRoot);

        $files = $tracer->trace([ApiAuthController::class], maxDepth: 2);

        $filenames = array_map('basename', array_keys($files));

        // Controller must be included
        $this->assertContains('ApiAuthController.php', $filenames,
            'ApiAuthController.php must be in scope'
        );

        // Services injected into controller (hop 1) must be included
        $this->assertContains('AuthService.php', $filenames,
            'AuthService.php (injected in controller) must be in scope'
        );
        $this->assertContains('TokenService.php', $filenames,
            'TokenService.php (injected in controller) must be in scope'
        );

        // Repository injected into service (hop 2) must be included
        $this->assertContains('UserRepository.php', $filenames,
            'UserRepository.php (injected in AuthService) must be in scope'
        );
    }

    /** @test */
    public function test_dependency_tracer_excludes_register_controller(): void
    {
        $fixturesRoot = realpath(__DIR__ . '/Fixtures');
        $tracer       = new DependencyTracer($fixturesRoot);

        $files     = $tracer->trace([ApiAuthController::class], maxDepth: 2);
        $filenames = array_map('basename', array_keys($files));

        $this->assertNotContains('RegisterController.php', $filenames,
            'RegisterController.php must NOT appear in scope for the login controller'
        );
    }

    /** @test */
    public function test_dependency_tracer_excludes_provider_files(): void
    {
        $fixturesRoot = realpath(__DIR__ . '/Fixtures');
        $tracer       = new DependencyTracer($fixturesRoot);

        $files     = $tracer->trace([ApiAuthController::class], maxDepth: 2);
        $filenames = array_map('basename', array_keys($files));

        $this->assertNotContains('AppRouteServiceProvider.php', $filenames,
            'RouteServiceProvider must NOT appear in scope — it is not a controller dependency'
        );
    }

    // ─── Full pipeline: CodeScanner::buildContextForApi ──────────────────────

    /** @test */
    public function test_build_context_for_api_returns_correct_files_via_router(): void
    {
        $scanner = new CodeScanner();
        $context = $scanner->buildContextForApi(
            realpath(__DIR__ . '/Fixtures'),
            'v1/auth/login'
        );

        $this->assertSame('api', $context['scope']);

        $filenames = array_map('basename', array_keys($context['files']));

        $this->assertContains('ApiAuthController.php', $filenames);
        $this->assertContains('AuthService.php',       $filenames);
        $this->assertContains('TokenService.php',      $filenames);
        $this->assertContains('UserRepository.php',    $filenames);

        $this->assertNotContains('RegisterController.php',       $filenames);
        $this->assertNotContains('AppRouteServiceProvider.php',  $filenames);
    }

    /** @test */
    public function test_build_context_scope_for_register_does_not_include_auth_controller(): void
    {
        $scanner = new CodeScanner();
        $context = $scanner->buildContextForApi(
            realpath(__DIR__ . '/Fixtures'),
            'v1/auth/register'
        );

        $filenames = array_map('basename', array_keys($context['files']));

        $this->assertContains('RegisterController.php', $filenames);

        // ApiAuthController is a completely different route — must not appear
        $this->assertNotContains('ApiAuthController.php', $filenames,
            'ApiAuthController must not appear in scope when analyzing v1/auth/register'
        );
    }

    // ─── Exact file count assertions ─────────────────────────────────────────

    /** @test */
    public function test_login_scope_contains_exactly_four_files(): void
    {
        $scanner = new CodeScanner();
        $context = $scanner->buildContextForApi(
            realpath(__DIR__ . '/Fixtures'),
            'v1/auth/login'
        );

        // Exactly: ApiAuthController, AuthService, TokenService, UserRepository
        $this->assertCount(4, $context['files'],
            'Login API scope must contain exactly 4 files: controller + 2 services + 1 repository. ' .
            'Got: ' . implode(', ', array_map('basename', array_keys($context['files'])))
        );
    }
}
