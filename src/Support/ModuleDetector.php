<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Detects module-based Laravel project structures.
 *
 * Supported structures:
 *  1. nwidart/laravel-modules  → {root}/Modules/{Name}/
 *  2. Custom app modules       → {root}/app/Modules/{Name}/
 *  3. DDD / Domain             → {root}/app/Domain/{Name}/
 *  4. Any path configured in   → config('codeguardian.modules.paths')
 */
class ModuleDetector
{
    /** Known module root directories to check (relative to project root) */
    private const DEFAULT_MODULE_ROOTS = [
        'Modules',
        'app/Modules',
        'app/Domain',
        'src/Modules',
        'src/Domain',
    ];

    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Is this project module-based?
     */
    public function isModular(): bool
    {
        return ! empty($this->detectModuleRoot());
    }

    /**
     * Return all module names found in the project.
     *
     * @return array<string>  e.g. ['User', 'Order', 'Payment']
     */
    public function listModules(): array
    {
        $root = $this->detectModuleRoot();
        if (! $root) {
            return [];
        }

        $modules = [];
        foreach (new \DirectoryIterator($root) as $item) {
            if ($item->isDir() && ! $item->isDot()) {
                $modules[] = $item->getFilename();
            }
        }
        sort($modules);
        return $modules;
    }

    /**
     * Return metadata for all modules: name, path, controllers, routes, models.
     */
    public function getModuleMap(): array
    {
        $root = $this->detectModuleRoot();
        if (! $root) {
            return [];
        }

        $map = [];
        foreach ($this->listModules() as $name) {
            $modulePath = $root . '/' . $name;
            $map[$name] = [
                'name'        => $name,
                'path'        => $modulePath,
                'rel_path'    => $this->relativePath($modulePath),
                'controllers' => $this->findFilesInModule($modulePath, 'Controller'),
                'models'      => $this->findFilesInModule($modulePath, 'Model'),
                'services'    => $this->findFilesInModule($modulePath, 'Service'),
                'routes'      => $this->findRouteFiles($modulePath),
                'requests'    => $this->findFilesInModule($modulePath, 'Request'),
                'providers'   => $this->findFilesInModule($modulePath, 'Provider'),
            ];
        }

        return $map;
    }

    /**
     * Get all PHP files for a specific module.
     *
     * @return array<string, string>  [ 'relativePath' => 'content' ]
     */
    public function getModuleFiles(string $moduleName): array
    {
        $root = $this->detectModuleRoot();
        if (! $root) {
            return [];
        }

        $modulePath = $root . '/' . $moduleName;
        if (! is_dir($modulePath)) {
            throw new \InvalidArgumentException("Module '{$moduleName}' not found at: {$modulePath}");
        }

        $scanner = new CodeScanner();
        return $scanner->scan($modulePath, 'laravel');
    }

    /**
     * Detect the module root directory (full path) or return null if not modular.
     */
    public function detectModuleRoot(): ?string
    {
        try {
            $extraPaths = config('codeguardian.modules.paths', []);
        } catch (\Throwable $e) {
            $extraPaths = [];
        }
        $toCheck = array_merge(self::DEFAULT_MODULE_ROOTS, $extraPaths);

        foreach ($toCheck as $relPath) {
            $fullPath = $this->projectRoot . '/' . $relPath;
            if (is_dir($fullPath) && $this->hasSubdirectories($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }

    /**
     * Return the type of module structure detected.
     */
    public function detectStructureType(): string
    {
        $root = $this->detectModuleRoot();
        if (! $root) {
            return 'standard';
        }

        $rel = $this->relativePath($root);
        return match (true) {
            $rel === 'Modules'       => 'nwidart',
            str_contains($rel, 'Domain') => 'ddd',
            default                  => 'custom',
        };
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function hasSubdirectories(string $dir): bool
    {
        foreach (new \DirectoryIterator($dir) as $item) {
            if ($item->isDir() && ! $item->isDot()) {
                return true;
            }
        }
        return false;
    }

    private function findFilesInModule(string $modulePath, string $suffix): array
    {
        $found  = [];
        $suffix = strtolower($suffix);

        if (! is_dir($modulePath)) {
            return $found;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filename = strtolower($file->getFilename());

            if (str_contains($filename, $suffix) || $this->directoryContains($file->getPath(), $suffix)) {
                $found[] = $this->relativePath($file->getPathname());
            }
        }

        return $found;
    }

    private function directoryContains(string $path, string $keyword): bool
    {
        return str_contains(strtolower($path), $keyword);
    }

    private function findRouteFiles(string $modulePath): array
    {
        $routes = [];
        $dirs   = [
            $modulePath . '/Routes',
            $modulePath . '/routes',
            $modulePath . '/Http/routes',
        ];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*.php') as $file) {
                $routes[] = $this->relativePath($file);
            }
        }

        return $routes;
    }

    private function relativePath(string $absolute): string
    {
        return ltrim(str_replace($this->projectRoot, '', $absolute), '/\\');
    }
}
