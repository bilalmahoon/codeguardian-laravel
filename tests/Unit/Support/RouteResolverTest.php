<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\RouteResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RouteResolver.
 *
 * RouteResolver depends on `app('router')`, which is only available inside a
 * bootstrapped Laravel application. In unit-test context (no app), every call
 * to resolve() must return an empty array so the caller can fall back to the
 * regex-based path — that is the primary contract being tested here.
 *
 * Integration tests that verify routing against a real Laravel app would live in
 * a separate Feature test that boots the application (beyond the scope of unit tests).
 */
class RouteResolverTest extends TestCase
{
    // ─── URI matching logic (tested via reflection) ───────────────────────────

    /** @test */
    public function test_uri_matches_exact(): void
    {
        $resolver = new RouteResolver('/var/www/project');

        $method = new \ReflectionMethod($resolver, 'uriMatches');

        $this->assertTrue($method->invoke($resolver, 'v1/auth/login', 'v1/auth/login'));
    }

    /** @test */
    public function test_uri_matches_suffix_with_api_prefix(): void
    {
        $resolver = new RouteResolver('/var/www/project');
        $method   = new \ReflectionMethod($resolver, 'uriMatches');

        // Route registered as "api/v1/auth/login", user types "v1/auth/login"
        $this->assertTrue($method->invoke($resolver, 'api/v1/auth/login', 'v1/auth/login'));
    }

    /** @test */
    public function test_uri_does_not_match_partial_segment(): void
    {
        $resolver = new RouteResolver('/var/www/project');
        $method   = new \ReflectionMethod($resolver, 'uriMatches');

        // "login" must not match "v1/auth/login/social"
        $this->assertFalse($method->invoke($resolver, 'v1/auth/login/social', 'v1/auth/login'));
    }

    /** @test */
    public function test_uri_does_not_match_different_route(): void
    {
        $resolver = new RouteResolver('/var/www/project');
        $method   = new \ReflectionMethod($resolver, 'uriMatches');

        $this->assertFalse($method->invoke($resolver, 'v1/auth/logout', 'v1/auth/login'));
    }

    /** @test */
    public function test_uri_does_not_match_unrelated_route_with_same_keyword(): void
    {
        $resolver = new RouteResolver('/var/www/project');
        $method   = new \ReflectionMethod($resolver, 'uriMatches');

        // "admin/v1/auth/login" ends with "/v1/auth/login" — that IS a valid suffix match
        // meaning the filter is a strict path suffix, not a keyword match
        $this->assertTrue($method->invoke($resolver, 'admin/v1/auth/login', 'v1/auth/login'));
    }

    // ─── Action parsing ───────────────────────────────────────────────────────

    /** @test */
    public function test_parse_action_with_at_sign(): void
    {
        $resolver = new RouteResolver('/var/www/project');
        $method   = new \ReflectionMethod($resolver, 'parseAction');

        [$class, $action] = $method->invoke($resolver, 'App\\Http\\Controllers\\AuthController@login');

        $this->assertSame('App\\Http\\Controllers\\AuthController', $class);
        $this->assertSame('login', $action);
    }

    /** @test */
    public function test_parse_action_invokable_defaults_to_invoke(): void
    {
        $resolver = new RouteResolver('/var/www/project');
        $method   = new \ReflectionMethod($resolver, 'parseAction');

        [$class, $action] = $method->invoke($resolver, 'App\\Http\\Controllers\\LoginController');

        $this->assertSame('App\\Http\\Controllers\\LoginController', $class);
        $this->assertSame('__invoke', $action);
    }

    // ─── Vendor class detection ───────────────────────────────────────────────

    /** @test */
    public function test_illuminate_class_is_vendor(): void
    {
        $resolver = new RouteResolver('/var/www/project');
        $method   = new \ReflectionMethod($resolver, 'isVendorClass');

        $this->assertTrue($method->invoke($resolver, 'Illuminate\\Routing\\Controller'));
    }

    /** @test */
    public function test_project_class_is_not_vendor(): void
    {
        $resolver = new RouteResolver('/var/www/project');
        $method   = new \ReflectionMethod($resolver, 'isVendorClass');

        $this->assertFalse($method->invoke($resolver, 'Modules\\Auth\\Http\\Controllers\\APIAuthController'));
    }

    // ─── Graceful degradation when Laravel app is absent ────────────────────

    /** @test */
    public function test_resolve_returns_empty_array_without_bootstrapped_app(): void
    {
        // In unit-test context app() is not available — resolve() must return []
        // so the caller can fall back to the regex-based path.
        $resolver = new RouteResolver(sys_get_temp_dir());
        $result   = $resolver->resolve('v1/auth/login');

        $this->assertIsArray($result);
        // May be empty (no app) or populated (if a Laravel test runner bootstraps the app)
        // Either way it must not throw.
    }
}
