<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\RouteExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RouteExtractor — specifically the prefix-group resolution
 * that caused "No routes matching 'v1/auth/login' found" even when the
 * route exists inside nested Route::prefix() groups.
 *
 * Root cause: the previous parser read raw URIs without resolving parent
 * prefix groups, so a route registered as Route::post('login') inside
 * Route::prefix('v1')->prefix('auth') was stored as URI '/login'
 * instead of '/v1/auth/login'.
 */
class RouteExtractorTest extends TestCase
{
    private string $tmpDir;
    private RouteExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir   = sys_get_temp_dir() . '/cg_route_test_' . uniqid();
        mkdir($this->tmpDir . '/routes', 0755, true);
        $this->extractor = new RouteExtractor($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
        parent::tearDown();
    }

    // ─── Prefix resolution ────────────────────────────────────────────────────

    public function test_resolves_nested_prefix_group_using_method_chaining(): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Route;

        Route::prefix('v1')->group(function () {
            Route::prefix('auth')->group(function () {
                Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login'])->name('auth.login');
                Route::post('/register', [\App\Http\Controllers\AuthController::class, 'register'])->name('auth.register');
            });
            Route::prefix('users')->group(function () {
                Route::get('/', [\App\Http\Controllers\UserController::class, 'index'])->name('users.index');
                Route::post('/', [\App\Http\Controllers\UserController::class, 'store'])->name('users.store');
            });
        });
        PHP);

        $routes = $this->extractor->extractAll();

        $uris = array_column($routes, 'uri');

        $this->assertContainsUri('/v1/auth/login', $uris, 'POST /v1/auth/login must be resolved');
        $this->assertContainsUri('/v1/auth/register', $uris);
        $this->assertContainsUri('/v1/users', $uris);
    }

    public function test_resolves_prefix_from_group_array_syntax(): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Route;

        Route::group(['prefix' => 'api/v1'], function () {
            Route::group(['prefix' => 'auth'], function () {
                Route::post('login', [\App\Http\Controllers\AuthController::class, 'login']);
            });
        });
        PHP);

        $routes = $this->extractor->extractAll();
        $uris   = array_column($routes, 'uri');

        $this->assertContainsUri('api/v1/auth/login', $uris, 'Group-array prefix must be resolved');
    }

    public function test_resolves_flat_routes_without_prefix(): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Route;

        Route::get('/health', function () { return 'ok'; });
        Route::post('/webhook', [\App\Http\Controllers\WebhookController::class, 'handle']);
        PHP);

        $routes = $this->extractor->extractAll();
        $uris   = array_column($routes, 'uri');

        $this->assertContainsUri('/health', $uris);
        $this->assertContainsUri('/webhook', $uris);
    }

    public function test_resolves_middleware_plus_prefix_combination(): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Route;

        Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
            Route::prefix('orders')->group(function () {
                Route::get('/', [\App\Http\Controllers\OrderController::class, 'index']);
                Route::delete('/{id}', [\App\Http\Controllers\OrderController::class, 'destroy']);
            });
        });
        PHP);

        $routes = $this->extractor->extractAll();
        $uris   = array_column($routes, 'uri');

        $this->assertContainsUri('/v1/orders', $uris);
    }

    // ─── filter() method ─────────────────────────────────────────────────────

    public function test_filter_finds_route_by_exact_uri(): void
    {
        $routes = $this->makeRouteList();

        $result = $this->extractor->filter($routes, '/v1/auth/login');
        $this->assertCount(1, $result);
        $this->assertSame('/v1/auth/login', $result[0]['uri']);
    }

    public function test_filter_finds_route_without_leading_slash(): void
    {
        $routes = $this->makeRouteList();

        $result = $this->extractor->filter($routes, 'v1/auth/login');
        $this->assertNotEmpty($result, "Filter 'v1/auth/login' should match '/v1/auth/login'");
    }

    public function test_filter_finds_route_by_partial_trailing_segments(): void
    {
        $routes = $this->makeRouteList();

        // User types "auth/login" — should match "/v1/auth/login"
        $result = $this->extractor->filter($routes, 'auth/login');
        $this->assertNotEmpty($result, "Filter 'auth/login' should match route ending in auth/login");
    }

    public function test_filter_finds_route_by_method_colon_uri(): void
    {
        $routes = $this->makeRouteList();

        $result = $this->extractor->filter($routes, 'POST:v1/auth/login');
        $this->assertCount(1, $result);
        $this->assertSame('POST', $result[0]['method']);
    }

    public function test_filter_returns_empty_for_nonexistent_route(): void
    {
        $routes = $this->makeRouteList();

        $result = $this->extractor->filter($routes, 'v2/nonexistent/endpoint');
        $this->assertEmpty($result);
    }

    public function test_filter_is_case_insensitive_for_method(): void
    {
        $routes = $this->makeRouteList();

        $result = $this->extractor->filter($routes, 'post:v1/auth/login');
        $this->assertNotEmpty($result);
    }

    public function test_filter_finds_by_controller_name(): void
    {
        $routes = $this->makeRouteList();

        $result = $this->extractor->filter($routes, 'AuthController');
        $this->assertNotEmpty($result);
    }

    // ─── Resource routes ────────────────────────────────────────────────────

    public function test_resolves_api_resource_inside_prefix(): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Route;
        use App\Http\Controllers\ProductController;

        Route::prefix('v1')->group(function () {
            Route::apiResource('products', ProductController::class);
        });
        PHP);

        $routes = $this->extractor->extractAll();
        $uris   = array_column($routes, 'uri');

        $this->assertContainsUri('/v1/products', $uris);
    }

    // ─── Controller extraction ───────────────────────────────────────────────

    public function test_extracts_array_syntax_controller(): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        Route::post('/login', [\App\Http\Controllers\Auth\LoginController::class, 'login']);
        PHP);

        $routes = $this->extractor->extractAll();
        $this->assertNotEmpty($routes);
        $this->assertStringContainsString('LoginController', $routes[0]['controller']);
        $this->assertStringContainsString('@login', $routes[0]['controller']);
    }

    public function test_extracts_invokable_controller(): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        Route::post('/login', \App\Http\Controllers\Auth\LoginAction::class);
        PHP);

        $routes = $this->extractor->extractAll();
        $this->assertNotEmpty($routes);
        $this->assertStringContainsString('LoginAction', $routes[0]['controller']);
        $this->assertStringContainsString('@__invoke', $routes[0]['controller']);
    }

    public function test_extracts_legacy_string_syntax_controller(): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', <<<'PHP'
        <?php
        Route::post('/login', 'App\Http\Controllers\AuthController@login');
        PHP);

        $routes = $this->extractor->extractAll();
        $this->assertNotEmpty($routes);
        $this->assertStringContainsString('AuthController@login', $routes[0]['controller']);
    }

    // ─── False-positive regression ────────────────────────────────────────────

    /**
     * Regression: filter('v1/auth/login') must NOT match a short route like '/login'
     * or '/auth' just because the filter string contains those segments.
     * Previously str_contains($normFilter, $normRoute) caused RouteServiceProvider
     * to be pulled in via the web /login route.
     */
    public function test_filter_does_not_false_positive_short_routes(): void
    {
        $routes = [
            ['method' => 'GET',  'uri' => '/login',            'controller' => 'RouteServiceProvider@boot', 'name' => null, 'source_file' => 'routes/web.php',  'line' => 1],
            ['method' => 'GET',  'uri' => '/auth',             'controller' => 'SomeController@auth',       'name' => null, 'source_file' => 'routes/web.php',  'line' => 2],
            ['method' => 'POST', 'uri' => '/v1/auth/login',    'controller' => 'AuthController@login',      'name' => null, 'source_file' => 'routes/api.php',  'line' => 5],
        ];

        $result = $this->extractor->filter($routes, 'v1/auth/login');

        // Only the real API route must be returned — NOT /login or /auth
        $this->assertCount(1, $result, 'Filter must not match short sub-string routes');
        $this->assertSame('/v1/auth/login', $result[0]['uri']);
    }

    public function test_resolveControllerFile_skips_closure_and_unknown(): void
    {
        $closure  = ['method' => 'GET', 'uri' => '/health',    'controller' => 'Closure',  'name' => null, 'source_file' => 'routes/api.php', 'line' => 1];
        $unknown  = ['method' => 'GET', 'uri' => '/something', 'controller' => 'Unknown',  'name' => null, 'source_file' => 'routes/api.php', 'line' => 2];
        $empty    = ['method' => 'GET', 'uri' => '/other',     'controller' => '',          'name' => null, 'source_file' => 'routes/api.php', 'line' => 3];

        $this->assertNull($this->extractor->resolveControllerFile($closure));
        $this->assertNull($this->extractor->resolveControllerFile($unknown));
        $this->assertNull($this->extractor->resolveControllerFile($empty));
    }

    // ─── Edge cases ──────────────────────────────────────────────────────────

    public function test_empty_route_file_returns_empty_array(): void
    {
        file_put_contents($this->tmpDir . '/routes/api.php', '<?php // empty');
        $routes = $this->extractor->extractAll();
        $this->assertIsArray($routes);
        $this->assertEmpty($routes);
    }

    public function test_nonexistent_route_file_is_silently_skipped(): void
    {
        // No api.php created
        $routes = $this->extractor->extractAll();
        $this->assertIsArray($routes);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeRouteList(): array
    {
        return [
            ['method' => 'POST', 'uri' => '/v1/auth/login',    'controller' => 'AuthController@login',    'name' => 'auth.login',    'source_file' => 'routes/api.php', 'line' => 5],
            ['method' => 'POST', 'uri' => '/v1/auth/register', 'controller' => 'AuthController@register', 'name' => 'auth.register', 'source_file' => 'routes/api.php', 'line' => 6],
            ['method' => 'GET',  'uri' => '/v1/users',         'controller' => 'UserController@index',    'name' => 'users.index',   'source_file' => 'routes/api.php', 'line' => 9],
            ['method' => 'GET',  'uri' => '/health',           'controller' => 'Closure',                 'name' => null,            'source_file' => 'routes/api.php', 'line' => 14],
        ];
    }

    private function assertContainsUri(string $expected, array $uris, string $message = ''): void
    {
        $normalized = fn($u) => ltrim(trim($u, '/'), '/');
        $normExpected = $normalized($expected);

        foreach ($uris as $uri) {
            if ($normalized($uri) === $normExpected) {
                $this->assertTrue(true);
                return;
            }
        }

        $this->fail(
            ($message ? $message . "\n" : '') .
            "URI '{$expected}' not found in routes. Available URIs:\n  " .
            implode("\n  ", $uris)
        );
    }

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
