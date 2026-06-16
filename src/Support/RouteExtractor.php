<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Parses Laravel route files and extracts route definitions.
 *
 * Returns structured route list:
 * [
 *   ['method' => 'GET', 'uri' => '/api/users', 'controller' => 'UserController@index', 'file' => '...'],
 *   ...
 * ]
 */
class RouteExtractor
{
    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Extract all routes from the project.
     */
    public function extractAll(): array
    {
        $routeFiles = $this->findAllRouteFiles();
        return $this->parseRouteFiles($routeFiles);
    }

    /**
     * Extract routes from a specific module.
     */
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
     * Find routes matching a specific URI pattern or HTTP method.
     *
     * @param  string $filter  e.g. "GET:/api/users", "/api/users", "POST", "users"
     */
    public function filter(array $routes, string $filter): array
    {
        if (str_contains($filter, ':')) {
            [$method, $uri] = explode(':', $filter, 2);
            $method = strtoupper($method);
            return array_values(array_filter($routes, fn($r) =>
                strtoupper($r['method']) === $method &&
                (str_contains($r['uri'], $uri) || fnmatch('*' . $uri . '*', $r['uri']))
            ));
        }

        // Filter by URI pattern or controller keyword
        return array_values(array_filter($routes, fn($r) =>
            str_contains($r['uri'], $filter) ||
            str_contains(strtolower($r['controller'] ?? ''), strtolower($filter))
        ));
    }

    /**
     * Get the controller file path for a route.
     * Returns null if the file cannot be found.
     */
    public function resolveControllerFile(array $route): ?string
    {
        $controller = $route['controller'] ?? null;
        if (! $controller) {
            return null;
        }

        // Strip method name: UserController@index → UserController
        $className = explode('@', $controller)[0];

        // Search for the class file
        return $this->findClassFile($className);
    }

    /**
     * Group routes by controller.
     */
    public function groupByController(array $routes): array
    {
        $grouped = [];
        foreach ($routes as $route) {
            $controller = $route['controller'] ?? 'closure';
            $grouped[$controller][] = $route;
        }
        return $grouped;
    }

    // ─── Parsers ─────────────────────────────────────────────────────────────

    private function findAllRouteFiles(): array
    {
        $files = [];

        // Standard Laravel route files
        $standard = [
            $this->projectRoot . '/routes/api.php',
            $this->projectRoot . '/routes/web.php',
            $this->projectRoot . '/routes/channels.php',
        ];

        foreach ($standard as $f) {
            if (file_exists($f)) {
                $files[] = $f;
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

    private function parseRouteFiles(array $files): array
    {
        $routes = [];

        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $content    = file_get_contents($file);
            $relFile    = ltrim(str_replace($this->projectRoot, '', $file), '/');
            $fileRoutes = $this->parseRouteContent($content, $relFile);
            $routes     = array_merge($routes, $fileRoutes);
        }

        return $routes;
    }

    private function parseRouteContent(string $content, string $sourceFile): array
    {
        $routes = [];

        // Match Route::METHOD('uri', [...]) patterns
        $pattern = '/Route\s*::\s*(get|post|put|patch|delete|any|match)\s*\(\s*[\'"]([^\'"]+)[\'"]/i';

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $method      = strtoupper($match[1][0]);
            $uri         = $match[2][0];
            $offset      = $match[0][1];
            $controller  = $this->extractController($content, $offset);
            $name        = $this->extractRouteName($content, $offset);
            $lineNumber  = substr_count(substr($content, 0, $offset), "\n") + 1;

            $routes[] = [
                'method'      => $method,
                'uri'         => $uri,
                'controller'  => $controller,
                'name'        => $name,
                'source_file' => $sourceFile,
                'line'        => $lineNumber,
            ];
        }

        // Match Route::resource / apiResource
        $resourcePattern = '/Route\s*::\s*(resource|apiResource)\s*\(\s*[\'"]([^\'"]+)[\'"],\s*([A-Za-z\\\\]+)::class/';
        preg_match_all($resourcePattern, $content, $resMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($resMatches as $match) {
            $type       = $match[1][0];
            $uri        = $match[2][0];
            $controller = str_replace('\\', '\\', $match[3][0]);
            $offset     = $match[0][1];
            $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;

            $methods = $type === 'apiResource'
                ? ['GET', 'POST', 'GET:{id}', 'PUT:{id}', 'PATCH:{id}', 'DELETE:{id}']
                : ['GET', 'POST', 'GET:{id}', 'PUT:{id}', 'PATCH:{id}', 'DELETE:{id}', 'GET:create', 'GET:edit'];

            foreach ($methods as $method) {
                $routes[] = [
                    'method'      => $method,
                    'uri'         => $uri,
                    'controller'  => $controller . ' (resource)',
                    'name'        => $uri . '.*',
                    'source_file' => $sourceFile,
                    'line'        => $lineNumber,
                ];
            }
        }

        return $routes;
    }

    private function extractController(string $content, int $offset): string
    {
        // Look ahead ~300 chars from the route definition to find controller
        $chunk = substr($content, $offset, 400);

        // [ControllerClass::class, 'method']
        if (preg_match('/\[\s*([A-Za-z\\\\]+)::class\s*,\s*[\'"](\w+)[\'"]\s*\]/', $chunk, $m)) {
            $class = class_basename(str_replace('\\', '/', $m[1]));
            return $class . '@' . $m[2];
        }

        // 'ControllerClass@method'
        if (preg_match('/[\'"]([A-Za-z\\\\]+@\w+)[\'"]/', $chunk, $m)) {
            return $m[1];
        }

        // Closure
        if (str_contains(substr($chunk, 0, 50), 'function')) {
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
        $shortName = class_basename(str_replace('\\', '/', $className));

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
                if ($file->getExtension() === 'php' &&
                    str_contains($file->getFilename(), $shortName)) {
                    return ltrim(str_replace($this->projectRoot, '', $file->getPathname()), '/');
                }
            }
        }

        return null;
    }
}
