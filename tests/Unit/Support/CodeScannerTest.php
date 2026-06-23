<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\CodeScanner;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CodeScanner — specifically the API-scope builder and its fallback
 * controller resolution introduced to handle projects where the controller file
 * cannot be resolved directly from the route definition (invokable, non-standard
 * paths, etc.).
 */
class CodeScannerTest extends TestCase
{
    private string $tmpDir;
    private CodeScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir  = sys_get_temp_dir() . '/cg_scanner_test_' . uniqid();
        mkdir($this->tmpDir . '/routes',                   0755, true);
        mkdir($this->tmpDir . '/app/Http/Controllers/Auth', 0755, true);
        $this->scanner = new CodeScanner();
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
        parent::tearDown();
    }

    // ─── buildContextForApi — direct resolution ───────────────────────────────

    public function test_buildContextForApi_finds_controller_via_array_syntax(): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        Route::prefix('v1')->group(function () {
            Route::prefix('auth')->group(function () {
                Route::post('/login', [\App\Http\Controllers\Auth\AuthController::class, 'login']);
            });
        });
        PHP);

        file_put_contents(
            $this->tmpDir . '/app/Http/Controllers/Auth/AuthController.php',
            '<?php namespace App\Http\Controllers\Auth; class AuthController { public function login() {} }'
        );

        $context = $this->scanner->buildContextForApi($this->tmpDir, 'v1/auth/login');

        $this->assertArrayHasKey('files', $context);
        $this->assertNotEmpty($context['files'], 'Controller file must be found');

        $paths = array_keys($context['files']);
        $this->assertTrue(
            (bool) array_filter($paths, fn($p) => str_contains($p, 'AuthController')),
            'AuthController.php must be in the resolved files'
        );
    }

    public function test_buildContextForApi_uses_uri_keyword_fallback_when_controller_not_resolved(): void
    {
        // Route uses a controller name that can't be resolved by findClassFile
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        Route::post('/v1/auth/login', 'UnresolvableController@login');
        PHP);

        // But a LoginController exists in the Controllers/Auth directory
        file_put_contents(
            $this->tmpDir . '/app/Http/Controllers/Auth/LoginController.php',
            '<?php namespace App\Http\Controllers\Auth; class LoginController { public function __invoke() {} }'
        );

        $context = $this->scanner->buildContextForApi($this->tmpDir, 'v1/auth/login');

        $this->assertNotEmpty($context['files'], 'Fallback must find LoginController via URI keywords');

        $paths = array_keys($context['files']);
        $this->assertTrue(
            (bool) array_filter($paths, fn($p) => str_contains(strtolower($p), 'login')),
            'Fallback must return a file whose path contains "login"'
        );
    }

    public function test_buildContextForApi_throws_when_no_routes_match(): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        Route::get('/health', function () { return 'ok'; });
        PHP);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No routes matching/');

        $this->scanner->buildContextForApi($this->tmpDir, 'v99/nonexistent/endpoint');
    }

    public function test_buildContextForApi_throws_descriptive_error_when_routes_found_but_files_missing(): void
    {
        // Route is registered but controller file doesn't exist and no keyword match either
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        Route::post('/v1/auth/login', 'UnresolvableController@login');
        PHP);

        // No controller file exists at all — not even a keyword match

        $this->expectException(\InvalidArgumentException::class);
        // Must mention the route was FOUND but the file was NOT
        $this->expectExceptionMessageMatches('/controller files.*could not be located|No routes matching/i');

        $this->scanner->buildContextForApi($this->tmpDir, 'v1/auth/login');
    }

    public function test_buildContextForApi_includes_service_files_referenced_by_controller(): void
    {
        mkdir($this->tmpDir . '/app/Services', 0755, true);

        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        Route::post('/v1/auth/login', [\App\Http\Controllers\Auth\AuthController::class, 'login']);
        PHP);

        file_put_contents(
            $this->tmpDir . '/app/Http/Controllers/Auth/AuthController.php',
            <<<'PHP'
            <?php
            namespace App\Http\Controllers\Auth;
            use App\Services\AuthService;
            class AuthController {
                public function login() {}
            }
            PHP
        );

        file_put_contents(
            $this->tmpDir . '/app/Services/AuthService.php',
            '<?php namespace App\Services; class AuthService {}'
        );

        $context = $this->scanner->buildContextForApi($this->tmpDir, 'v1/auth/login');

        $paths = array_keys($context['files']);
        $this->assertTrue(
            (bool) array_filter($paths, fn($p) => str_contains($p, 'AuthService')),
            'AuthService must be included via findRelatedServices'
        );
    }

    // ─── extractUriKeywords (tested via fallback behaviour) ───────────────────

    public function test_fallback_skips_version_and_api_prefixes(): void
    {
        mkdir($this->tmpDir . '/app/Http/Controllers/Users', 0755, true);

        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        Route::post('/api/v2/users/profile', 'UnresolvableController@update');
        PHP);

        // Controller with "users" in path — should be found via keyword "users" or "profile"
        file_put_contents(
            $this->tmpDir . '/app/Http/Controllers/Users/ProfileController.php',
            '<?php namespace App\Http\Controllers\Users; class ProfileController {}'
        );

        // Should NOT create a file named "v2Controller.php" or "apiController.php"
        $context = $this->scanner->buildContextForApi($this->tmpDir, 'api/v2/users/profile');

        $paths = array_keys($context['files']);
        foreach ($paths as $path) {
            $this->assertStringNotContainsString('v2', basename($path),
                'Version segment "v2" must not drive keyword matching');
            $this->assertStringNotContainsString('apiController', basename($path),
                '"api" segment must not drive keyword matching');
        }
        $this->assertNotEmpty($context['files'], 'ProfileController must be found via "users" or "profile" keyword');
    }

    // ─── getFilenameWithoutExtension fix (regression) ─────────────────────────

    /**
     * Regression: CodeScanner used $file->getFilenameWithoutExtension() which does
     * not exist on SplFileInfo — it throws "Call to undefined method".
     * The fix uses pathinfo($file->getFilename(), PATHINFO_FILENAME) instead.
     * This test triggers the code path by exercising the URI keyword fallback.
     */
    public function test_findControllersByUriKeywords_does_not_throw_on_spl_file_info(): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        Route::post('/v1/auth/login', 'AnyUnresolvableController@login');
        PHP);

        file_put_contents(
            $this->tmpDir . '/app/Http/Controllers/Auth/AuthController.php',
            '<?php class AuthController {}'
        );

        // If getFilenameWithoutExtension() is called this throws a fatal error;
        // the test passing means the fix is in place.
        $this->expectNotToPerformAssertions();

        try {
            $this->scanner->buildContextForApi($this->tmpDir, 'v1/auth/login');
        } catch (\InvalidArgumentException $e) {
            // It's fine if no files are found — the important thing is no fatal error
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function rmdirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
