<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Parses Laravel route files and extracts route definitions with full URI resolution.
 *
 * Handles:
 *   Route::prefix('v1')->group(...)
 *   Route::group(['prefix' => 'v1'], ...)
 *   Route::middleware([...])->prefix('v1')->group(...)
 *   Nested groups at any depth
 *   Route::resource() / Route::apiResource()
 *
 * Root cause of the original "No routes matching" bug:
 *   The previous parser read raw URIs from route definitions without resolving
 *   parent prefix groups. A route declared as Route::post('login', ...) inside
 *   Route::prefix('v1')->prefix('auth')->group() would be stored with URI 'login'
 *   instead of 'v1/auth/login'.
 *   The new parser tracks a prefix stack using brace-depth traversal.
 */
class RouteExtractor
{
    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    public function extractAll(): array
    {
        $routeFiles = $this->findAllRouteFiles();
        return $this->parseRouteFiles($routeFiles);
    }

    public function extractForModule(string $moduleName): array
    {
        $moduleDetector = new ModuleDetector($this->projectRoot);
        $moduleMap      = $moduleDetector->getModuleMap();

        if (! isset($moduleMap[$moduleName])) {
            return [];
        }

        $routeFiles = array_map(
            fn($r) => $this->projectRoot . '/' . $r,
            $moduleMap[$moduleName]['routes']
        );

        return $this->parseRouteFiles($routeFiles);
    }

    /**
     * Filter routes by a URI pattern, HTTP method, or controller keyword.
     *
     * Examples:
     *   "POST:/api/v1/auth/login"   — exact method + URI
     *   "v1/auth/login"             — URI substring (any method)
     *   "auth/login"                — URI substring
     *   "AuthController"            — controller name keyword
     *
     * Matching is intentionally loose to handle prefix variations:
     *   The user may type "v1/auth/login" while the stored URI is "/v1/auth/login"
     *   or "api/v1/auth/login". We normalise and strip leading slashes before comparing.
     */
    public function filter(array $routes, string $filter): array
    {
        if (str_contains($filter, ':')) {
            [$method, $uriFilter] = explode(':', $filter, 2);
            $method    = strtoupper(trim($method));
            $uriFilter = trim($uriFilter);
            return array_values(array_filter($routes, fn($r) =>
                strtoupper($r['method']) === $method &&
                $this->uriMatches($r['uri'], $uriFilter)
            ));
        }

        return array_values(array_filter($routes, fn($r) =>
            $this->uriMatches($r['uri'], $filter) ||
            str_contains(strtolower($r['controller'] ?? ''), strtolower($filter))
        ));
    }

    public function resolveControllerFile(array $route): ?string
    {
        $controller = $route['controller'] ?? null;
        if (! $controller || in_array($controller, ['Closure', 'Unknown', ''], true)) {
            return null;
        }
        $className = explode('@', $controller)[0];
        return $this->findClassFile($className);
    }

    public function groupByController(array $routes): array
    {
        $grouped = [];
        foreach ($routes as $route) {
            $controller = $route['controller'] ?? 'closure';
            $grouped[$controller][] = $route;
        }
        return $grouped;
    }

    // ─── URI matching ─────────────────────────────────────────────────────────

    /**
     * Flexible URI matching:
     *   - Normalise: strip leading slash and optional "api/" prefix for comparison
     *   - Try exact contains first
     *   - Try matching the last N segments of the filter against the URI
     */
    private function uriMatches(string $routeUri, string $filter): bool
    {
        $normaliseUri    = fn($u) => ltrim(preg_replace('#^/?api/#', '', ltrim($u, '/')), '/');
        $normRoute  = $normaliseUri($routeUri);
        $normFilter = $normaliseUri($filter);

        // Guard: str_contains(haystack, '') is ALWAYS true in PHP.
        // Both strings must be non-empty before we do substring matching.
        //
        // IMPORTANT: only check if the ROUTE contains the FILTER, never the reverse.
        // str_contains($normFilter, $normRoute) would mean any short route like "/auth"
        // or "/login" falsely matches the filter "v1/auth/login" — that causes wrong
        // files like RouteServiceProvider to be included in the scope.
        if ($normFilter !== '' && $normRoute !== '') {
            if (str_contains($normRoute, $normFilter)) {
                return true;
            }
        }

        // Try matching trailing segments: filter "v1/auth/login" matches route "/api/v1/auth/login"
        $filterParts = array_filter(explode('/', $normFilter));
        $routeParts  = array_filter(explode('/', $normRoute));

        if (count($filterParts) > 0 && count($routeParts) >= count($filterParts)) {
            $routeTail = array_slice(array_values($routeParts), -count($filterParts));
            if ($routeTail === array_values($filterParts)) {
                return true;
            }
        }

        return false;
    }

    // ─── File discovery ───────────────────────────────────────────────────────

    private function findAllRouteFiles(): array
    {
        $files = [];

        foreach (['routes/api.php', 'routes/web.php', 'routes/channels.php'] as $rel) {
            $path = $this->projectRoot . '/' . $rel;
            if (file_exists($path)) {
                $files[] = $path;
            }
        }

        // Extra files in routes/ directory (e.g. routes/v1.php, routes/admin.php)
        $routesDir = $this->projectRoot . '/routes';
        if (is_dir($routesDir)) {
            foreach (new \DirectoryIterator($routesDir) as $item) {
                if ($item->isFile() && $item->getExtension() === 'php') {
                    $files[] = $item->getPathname();
                }
            }
        }

        // Module route files
        $moduleDetector = new ModuleDetector($this->projectRoot);
        if ($moduleDetector->isModular()) {
            foreach ($moduleDetector->getModuleMap() as $module) {
                foreach ($module['routes'] as $relPath) {
                    $files[] = $this->projectRoot . '/' . $relPath;
                }
            }
        }

        return array_unique($files);
    }

    // ─── Parsing ─────────────────────────────────────────────────────────────

    private function parseRouteFiles(array $files): array
    {
        $routes = [];
        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }
            $content    = file_get_contents($file);
            $relFile    = ltrim(str_replace($this->projectRoot, '', $file), '/');
            $routes     = array_merge($routes, $this->parseRouteContent($content, $relFile));
        }
        return $routes;
    }

    /**
     * Parse route definitions from a file, resolving nested prefix groups.
     *
     * Algorithm:
     *   Walk through lines tracking brace depth.
     *   When a prefix-group opener is found at depth D, push its prefix onto the stack.
     *   When a closing brace reduces depth to D, pop the prefix.
     *   Every route definition is annotated with the current prefix stack joined by '/'.
     */
    private function parseRouteContent(string $content, string $sourceFile): array
    {
        $routes = [];
        $lines  = explode("\n", $content);

        // prefixStack[depth] = prefix string at that brace depth
        $prefixStack    = [];
        $braceDepth     = 0;
        // Track which depth level each prefix was pushed at
        $depthOfPrefix  = [];

        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);

            // ── Detect prefix group openers ──────────────────────────────────
            $prefixValue = $this->extractPrefixFromLine($trimmed);

            // Count brace changes on this line
            $opens  = substr_count($line, '{') - substr_count($line, "'{'") - substr_count($line, '"{"');
            $closes = substr_count($line, '}') - substr_count($line, "'}'") - substr_count($line, '"}"');
            $opens  = max(0, (int) $opens);
            $closes = max(0, (int) $closes);

            // If a prefix is declared AND this line opens a group block
            if ($prefixValue !== null && $opens > 0) {
                $braceDepth += $opens;
                // Push prefix — it was declared at new depth
                $prefixStack[$braceDepth]   = $prefixValue;
                $depthOfPrefix[$braceDepth] = true;
                $braceDepth -= $opens; // will be re-applied below
            }

            // Apply opens first, then register prefix at that depth
            if ($prefixValue !== null && $opens > 0) {
                $newDepth = $braceDepth + $opens;
                $prefixStack[$newDepth] = $prefixValue;
            }

            $braceDepth += $opens;

            // On close, remove any prefix registered at depth being exited
            for ($i = 0; $i < $closes; $i++) {
                unset($prefixStack[$braceDepth]);
                $braceDepth = max(0, $braceDepth - 1);
            }

            // ── Parse route definitions ───────────────────────────────────────
            $currentPrefix = implode('/', array_filter(array_map(
                fn($p) => trim($p, '/'),
                $prefixStack
            )));

            // Route::get/post/put/patch/delete/any('uri', ...)
            $routePattern = '/Route\s*::\s*(get|post|put|patch|delete|any|match)\s*\(\s*[\'"]([^\'"]+)[\'"]/i';
            if (preg_match($routePattern, $trimmed, $m)) {
                $method     = strtoupper($m[1]);
                $rawUri     = ltrim($m[2], '/');
                $fullUri    = $this->joinUri($currentPrefix, $rawUri);
                $controller = $this->extractController($content, (int) strpos($content, $line));
                $name       = $this->extractRouteName($content, (int) strpos($content, $line));

                $routes[] = [
                    'method'      => $method,
                    'uri'         => '/' . ltrim($fullUri, '/'),
                    'controller'  => $controller,
                    'name'        => $name,
                    'source_file' => $sourceFile,
                    'line'        => $lineNum + 1,
                ];
            }

            // Route::resource / apiResource
            $resourcePattern = '/Route\s*::\s*(resource|apiResource)\s*\(\s*[\'"]([^\'"]+)[\'"],\s*([A-Za-z\\\\]+)::class/';
            if (preg_match($resourcePattern, $trimmed, $m)) {
                $rawUri     = ltrim($m[2], '/');
                $fullUri    = $this->joinUri($currentPrefix, $rawUri);
                $controller = str_replace('\\', '/', $m[3]);
                $methods    = ['GET', 'POST', 'GET:{id}', 'PUT:{id}', 'PATCH:{id}', 'DELETE:{id}'];

                foreach ($methods as $method) {
                    $routes[] = [
                        'method'      => $method,
                        'uri'         => '/' . ltrim($fullUri, '/'),
                        'controller'  => $controller . ' (resource)',
                        'name'        => $fullUri . '.*',
                        'source_file' => $sourceFile,
                        'line'        => $lineNum + 1,
                    ];
                }
            }
        }

        return $routes;
    }

    /**
     * Extract a prefix value from a line if it declares one.
     * Handles:
     *   Route::prefix('v1')->group(...)
     *   Route::group(['prefix' => 'v1'], ...)
     *   ->prefix('v1')
     */
    private function extractPrefixFromLine(string $line): ?string
    {
        // Route::prefix('value') or ->prefix('value')
        if (preg_match('/(?:Route\s*::\s*|->)prefix\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $line, $m)) {
            return $m[1];
        }

        // Route::group(['prefix' => 'value'], ...)
        if (preg_match('/Route\s*::\s*group\s*\(\s*\[.*[\'"]prefix[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $line, $m)) {
            return $m[1];
        }

        return null;
    }

    private function joinUri(string $prefix, string $segment): string
    {
        $prefix  = trim($prefix, '/');
        $segment = trim($segment, '/');

        if ($prefix === '') {
            return $segment;
        }
        if ($segment === '') {
            return $prefix;
        }
        return $prefix . '/' . $segment;
    }

    // ─── Controller extraction helpers ───────────────────────────────────────

    private function extractController(string $content, int $offset): string
    {
        // Use a 600-char window to handle multi-line route definitions
        $chunk = substr($content, $offset, 600);

        // Pattern 1: [ClassName::class, 'method']  (most common Laravel 8+)
        if (preg_match('/\[\s*([A-Za-z\\\\]+)::class\s*,\s*[\'"](\w+)[\'"]\s*\]/', $chunk, $m)) {
            $class = class_basename(str_replace('\\', '/', $m[1]));
            return $class . '@' . $m[2];
        }

        // Pattern 2: 'ClassName@method'  (legacy string syntax)
        if (preg_match('/[\'"]([A-Za-z\\\\]+@\w+)[\'"]/', $chunk, $m)) {
            return $m[1];
        }

        // Pattern 3: InvokableController::class  (single-action controller, no method)
        // Matches the second argument being just a class reference, not inside an array.
        if (preg_match('/Route\s*::\s*\w+\s*\([^,]+,\s*([A-Za-z\\\\]+)::class\s*[,)\n]/', $chunk, $m)) {
            $class = class_basename(str_replace('\\', '/', $m[1]));
            return $class . '@__invoke';
        }

        // Pattern 4: Route::controller(ClassName::class)->group(...) with short method string
        if (preg_match('/Route\s*::\s*controller\s*\(\s*([A-Za-z\\\\]+)::class\s*\)/', $chunk, $m)) {
            $class = class_basename(str_replace('\\', '/', $m[1]));
            return $class . '@(group)';
        }

        if (str_contains(substr($chunk, 0, 120), 'function')) {
            return 'Closure';
        }

        return 'Unknown';
    }

    private function extractRouteName(string $content, int $offset): ?string
    {
        $chunk = substr($content, $offset, 300);
        if (preg_match('/->name\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $chunk, $m)) {
            return $m[1];
        }
        return null;
    }

    private function findClassFile(string $className): ?string
    {
        $shortName  = class_basename(str_replace('\\', '/', $className));
        $searchDirs = [
            $this->projectRoot . '/app',
            $this->projectRoot . '/Modules',
            $this->projectRoot . '/src',
        ];

        foreach ($searchDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php' && str_contains($file->getFilename(), $shortName)) {
                    return ltrim(str_replace($this->projectRoot, '', $file->getPathname()), '/');
                }
            }
        }

        return null;
    }
}
