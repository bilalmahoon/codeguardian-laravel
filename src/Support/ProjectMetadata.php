<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * Collects pick-lists for the dashboard "New run" form so the user can choose a
 * target from searchable dropdowns instead of typing it by hand:
 *
 *   - modules   detected module names
 *   - apiRoutes / webRoutes  the app's own routes (vendor/framework filtered out)
 *   - commands  the app's own artisan commands (with the file that defines them)
 *
 * Everything degrades gracefully: if the router/console isn't available the
 * corresponding list is simply empty and the form falls back to free text.
 */
class ProjectMetadata
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    public static function forCurrentApp(): self
    {
        return new self(base_path());
    }

    /** @return array<int,string> */
    public function modules(): array
    {
        try {
            return (new ModuleDetector($this->projectRoot))->listModules();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * App routes split into api/web. Each entry: { uri, methods, name, action, type }.
     *
     * @return array{api:array<int,array<string,string>>,web:array<int,array<string,string>>}
     */
    public function routes(): array
    {
        $api = [];
        $web = [];

        try {
            $routes = app('router')->getRoutes();
        } catch (\Throwable) {
            return ['api' => [], 'web' => []];
        }

        $seen = [];
        foreach ($routes as $route) {
            $action = method_exists($route, 'getActionName') ? (string) $route->getActionName() : '';

            // Skip closure routes (no file to refactor) and vendor/framework routes.
            if ($action === '' || $action === 'Closure' || str_contains($action, '{closure}')) {
                continue;
            }
            if ($this->isVendorAction($action)) {
                continue;
            }

            $uri     = '/' . ltrim((string) $route->uri(), '/');
            $methods = array_values(array_diff((array) $route->methods(), ['HEAD']));
            $method  = $methods[0] ?? 'GET';
            $name    = method_exists($route, 'getName') ? (string) ($route->getName() ?? '') : '';

            $isApi = str_starts_with(ltrim($uri, '/'), 'api')
                || in_array('api', $this->middlewareOf($route), true);

            // The --api filter resolves by URI, so the URI is the value we pass.
            $value = ltrim($uri, '/');

            $key = $method . ' ' . $value;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $entry = [
                'uri'     => $value,
                'methods' => implode('|', $methods ?: ['GET']),
                'name'    => $name,
                'action'  => $this->shortAction($action),
            ];

            if ($isApi) {
                $api[] = $entry;
            } else {
                $web[] = $entry;
            }
        }

        usort($api, fn($a, $b) => strcmp($a['uri'], $b['uri']));
        usort($web, fn($a, $b) => strcmp($a['uri'], $b['uri']));

        return ['api' => $api, 'web' => $web];
    }

    /**
     * App artisan commands with the file that defines them (for --file targeting).
     *
     * @return array<int,array{name:string,file:string}>
     */
    public function commands(): array
    {
        $out = [];

        try {
            $all = Artisan::all();
        } catch (\Throwable) {
            return [];
        }

        foreach ($all as $name => $command) {
            try {
                $ref  = new \ReflectionClass($command);
                $file = (string) $ref->getFileName();
            } catch (\Throwable) {
                continue;
            }

            // Only the app's own commands (under project root, not vendor).
            if ($file === '' || ! str_starts_with($file, $this->projectRoot)) {
                continue;
            }
            if (str_contains($file, '/vendor/')) {
                continue;
            }

            $out[] = [
                'name' => (string) $name,
                'file' => ltrim(str_replace($this->projectRoot, '', $file), '/\\'),
            ];
        }

        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * First-party PHP source files, relative to the project root, for the
     * "file" target picker. Only real source dirs are scanned (app/, Modules/,
     * src/) — vendor/tests/storage/migrations are excluded. Capped so the form
     * payload and client-side filter stay fast on huge codebases.
     *
     * @return array<int,string>
     */
    public function files(int $cap = 4000): array
    {
        $roots = [];
        foreach (['app', 'Modules', 'src'] as $dir) {
            $abs = $this->projectRoot . '/' . $dir;
            if (is_dir($abs)) {
                $roots[] = $abs;
            }
        }

        $out = [];
        foreach ($roots as $root) {
            try {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
                );
            } catch (\Throwable) {
                continue;
            }

            foreach ($it as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $rel = ltrim(str_replace($this->projectRoot, '', $file->getPathname()), '/\\');
                // Skip non-source noise that may live under these roots.
                if (preg_match('#(^|/)(vendor|tests?|storage|node_modules)/#i', $rel)) {
                    continue;
                }
                $out[] = $rel;
                if (count($out) >= $cap) {
                    break 2;
                }
            }
        }

        sort($out);

        return $out;
    }

    /** Resolve an artisan command name to the relative file that defines it. */
    public function commandFile(string $name): ?string
    {
        foreach ($this->commands() as $command) {
            if ($command['name'] === $name) {
                return $command['file'];
            }
        }

        return null;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<int,string> */
    private function middlewareOf($route): array
    {
        try {
            return method_exists($route, 'gatherMiddleware')
                ? (array) $route->gatherMiddleware()
                : (array) ($route->middleware() ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    private function isVendorAction(string $action): bool
    {
        $vendorPrefixes = [
            'Illuminate\\', 'Laravel\\', 'Symfony\\', 'Livewire\\',
            'Spatie\\', 'Barryvdh\\', 'L5Swagger\\',
        ];
        foreach ($vendorPrefixes as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Trim the leading namespace from "App\Http\Controllers\X@method" for display. */
    private function shortAction(string $action): string
    {
        if (! str_contains($action, '@')) {
            return class_basename($action);
        }
        [$class, $method] = explode('@', $action, 2);

        return class_basename($class) . '@' . $method;
    }
}
