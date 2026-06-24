<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Resolves a URI filter to the exact controller class and method that handles it,
 * using Laravel's own Router — no regex parsing of route files.
 *
 * Why this is better than parsing route files:
 *   - Laravel's Router is the single source of truth for which controller handles a URI.
 *   - It automatically handles all route definition styles: array syntax, string syntax,
 *     Route::controller() groups, invokable controllers, prefixes, middleware groups, etc.
 *   - A file-based regex parser must re-implement all of those rules and will always lag
 *     behind or have edge cases. The Router already handles all of them by definition.
 */
class RouteResolver
{
    private string $projectRoot;

    /**
     * Namespace prefixes that belong to the framework itself.
     * If a route action points at one of these, it is not a project file.
     */
    private const VENDOR_NAMESPACES = [
        'Illuminate\\',
        'Laravel\\',
        'Symfony\\',
    ];

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
    }

    /**
     * Return every route that matches the given URI filter.
     *
     * Each entry:
     *   [
     *     'class'  => 'Modules\Auth\Http\Controllers\APIAuthController',
     *     'method' => 'authenticateUser',
     *     'uri'    => 'api/v1/auth/login',
     *     'http_methods' => ['POST'],
     *     'file'   => '/abs/path/to/APIAuthController.php',
     *   ]
     *
     * Returns an empty array when Laravel's Router is not available
     * (e.g. unit-test context without a full app bootstrap) — the caller
     * must fall back to the legacy regex approach in that case.
     */
    public function resolve(string $uriFilter): array
    {
        try {
            $router = app('router');
            $routes = $router->getRoutes();
        } catch (\Throwable) {
            return [];
        }

        $normalFilter = $this->normalizeUri($uriFilter);
        $matched      = [];

        foreach ($routes as $route) {
            $uri = $this->normalizeUri($route->uri());

            if (! $this->uriMatches($uri, $normalFilter)) {
                continue;
            }

            $action = $route->getAction('uses');

            // Closures have no inspectable file — skip them
            if (! $action || $action instanceof \Closure || ! is_string($action)) {
                continue;
            }

            [$class, $method] = $this->parseAction($action);

            // Skip vendor / framework controllers
            if ($this->isVendorClass($class)) {
                continue;
            }

            if (! class_exists($class)) {
                continue;
            }

            try {
                $file = (new \ReflectionClass($class))->getFileName();
            } catch (\ReflectionException) {
                continue;
            }

            if (! $file || ! file_exists($file)) {
                continue;
            }

            // De-duplicate: same class+method may appear on multiple HTTP verbs
            $key = $class . '@' . $method;
            if (! isset($matched[$key])) {
                $matched[$key] = [
                    'class'        => $class,
                    'method'       => $method,
                    'uri'          => $route->uri(),
                    'http_methods' => $route->methods(),
                    'file'         => $file,
                ];
            }
        }

        return array_values($matched);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function normalizeUri(string $uri): string
    {
        return ltrim(strtolower(trim($uri)), '/');
    }

    /**
     * A route URI matches the filter when:
     *   - It equals the filter exactly:          "v1/auth/login" == "v1/auth/login"
     *   - It ends with "/" + filter:             "api/v1/auth/login" ends with "/v1/auth/login"
     *
     * Intentionally NOT a substring match — "auth" must not match "v1/auth/login/social".
     */
    private function uriMatches(string $routeUri, string $filter): bool
    {
        return $routeUri === $filter
            || str_ends_with($routeUri, '/' . $filter);
    }

    /**
     * Split "ClassName@method" → ['ClassName', 'method'].
     * Invokable controllers have no "@" → method defaults to "__invoke".
     */
    private function parseAction(string $action): array
    {
        if (str_contains($action, '@')) {
            return explode('@', $action, 2);
        }

        return [$action, '__invoke'];
    }

    private function isVendorClass(string $class): bool
    {
        foreach (self::VENDOR_NAMESPACES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
