<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

class CodeScanner
{
    private const LARAVEL_EXTENSIONS = ['php'];
    private const FLUTTER_EXTENSIONS = ['dart'];

    private array $skipDirs;
    private int   $maxFileSize;

    public function __construct()
    {
        $this->skipDirs    = config('codeguardian.analysis.skip_dirs', [
            'vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache',
            '.dart_tool', 'build', '.pub-cache',
        ]);
        $this->maxFileSize = config('codeguardian.analysis.max_file_size', 100_000);
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Scan a directory and return file contents keyed by relative path.
     *
     * @return array<string, string>  [ 'app/Http/Controllers/Foo.php' => '<?php ...' ]
     */
    public function scan(string $directory, string $projectType = 'laravel'): array
    {
        $extensions = $projectType === 'flutter'
            ? self::FLUTTER_EXTENSIONS
            : self::LARAVEL_EXTENSIONS;

        $files = [];
        $this->scanDirectory($directory, $directory, $extensions, $files);
        return $files;
    }

    /**
     * Build a code context array ready for AI agents — full project.
     */
    public function buildContext(
        string  $directory,
        string  $projectType = 'laravel',
        ?string $projectName = null
    ): array {
        $files   = $this->scan($directory, $projectType);
        $summary = $this->buildSummary($files, $projectType);

        $context = [
            'project_type' => $projectType,
            'project_name' => $projectName ?? basename($directory),
            'scan_path'    => $directory,
            'files'        => $files,
            'summary'      => $summary,
            'scope'        => 'full',
        ];

        // Enrich with module info if modular project
        $moduleDetector = new ModuleDetector($directory);
        if ($moduleDetector->isModular()) {
            $context['is_modular']      = true;
            $context['module_structure'] = $moduleDetector->detectStructureType();
            $context['modules']         = array_keys($moduleDetector->getModuleMap());
        }

        return $context;
    }

    /**
     * Build context for a specific module only.
     */
    public function buildContextForModule(
        string $projectRoot,
        string $moduleName
    ): array {
        $detector = new ModuleDetector($projectRoot);

        if (! $detector->isModular()) {
            throw new \RuntimeException("Project at {$projectRoot} is not module-based.");
        }

        $modules = $detector->listModules();
        if (! in_array($moduleName, $modules, true)) {
            throw new \InvalidArgumentException(
                "Module '{$moduleName}' not found. Available: " . implode(', ', $modules)
            );
        }

        $moduleFiles = $detector->getModuleFiles($moduleName);
        $moduleMap   = $detector->getModuleMap()[$moduleName];
        $summary     = $this->buildSummary($moduleFiles, 'laravel');

        return [
            'project_type'   => 'laravel',
            'project_name'   => basename($projectRoot) . '/' . $moduleName,
            'scan_path'      => $projectRoot,
            'module_name'    => $moduleName,
            'module_path'    => $moduleMap['path'],
            'files'          => $moduleFiles,
            'summary'        => $summary,
            'scope'          => 'module',
            'is_modular'     => true,
            'module_info'    => $moduleMap,
        ];
    }

    /**
     * Build context for specific APIs (routes + their controller files only).
     */
    public function buildContextForApi(
        string $projectRoot,
        string $apiFilter
    ): array {
        $extractor    = new RouteExtractor($projectRoot);
        $allRoutes    = $extractor->extractAll();
        $filteredRoutes = $extractor->filter($allRoutes, $apiFilter);

        if (empty($filteredRoutes)) {
            throw new \InvalidArgumentException(
                "No routes matching '{$apiFilter}' found in the project."
            );
        }

        // Collect only the controller files involved
        $files = [];
        foreach ($filteredRoutes as $route) {
            $controllerFile = $extractor->resolveControllerFile($route);
            if ($controllerFile) {
                $fullPath = $projectRoot . '/' . $controllerFile;
                if (file_exists($fullPath) && filesize($fullPath) <= $this->maxFileSize) {
                    $files[$controllerFile] = file_get_contents($fullPath);
                }
            }
        }

        // Also include service files likely called by these controllers
        $files = array_merge($files, $this->findRelatedServices($projectRoot, $files));
        $summary = $this->buildSummary($files, 'laravel');

        return [
            'project_type'   => 'laravel',
            'project_name'   => basename($projectRoot) . " [{$apiFilter}]",
            'scan_path'      => $projectRoot,
            'api_filter'     => $apiFilter,
            'routes'         => $filteredRoutes,
            'files'          => $files,
            'summary'        => $summary,
            'scope'          => 'api',
        ];
    }

    /**
     * Return files for a single specific file path.
     */
    public function buildContextForFile(string $projectRoot, string $relFilePath): array
    {
        $fullPath = $projectRoot . '/' . ltrim($relFilePath, '/');

        if (! file_exists($fullPath)) {
            throw new \InvalidArgumentException("File not found: {$fullPath}");
        }

        $files   = [$relFilePath => file_get_contents($fullPath)];
        $summary = $this->buildSummary($files, 'laravel');

        return [
            'project_type' => 'laravel',
            'project_name' => basename($projectRoot),
            'scan_path'    => $projectRoot,
            'files'        => $files,
            'summary'      => $summary,
            'scope'        => 'file',
        ];
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function scanDirectory(
        string $rootDir,
        string $currentDir,
        array  $extensions,
        array  &$files
    ): void {
        if (! is_dir($currentDir)) {
            return;
        }

        $iterator = new \DirectoryIterator($currentDir);

        foreach ($iterator as $item) {
            if ($item->isDot()) {
                continue;
            }

            $name = $item->getFilename();

            if ($item->isDir()) {
                if (in_array($name, $this->skipDirs, true)) {
                    continue;
                }
                $this->scanDirectory($rootDir, $item->getPathname(), $extensions, $files);
                continue;
            }

            if (! in_array($item->getExtension(), $extensions, true)) {
                continue;
            }

            if ($item->getSize() > $this->maxFileSize) {
                continue;
            }

            $relativePath         = ltrim(str_replace($rootDir, '', $item->getPathname()), '/\\');
            $files[$relativePath] = file_get_contents($item->getPathname());
        }
    }

    /**
     * Find service/repository files referenced in controller files.
     */
    private function findRelatedServices(string $projectRoot, array $controllerFiles): array
    {
        $serviceFiles = [];

        foreach ($controllerFiles as $content) {
            // Find injected class names from constructor or method signatures
            preg_match_all('/use\s+([A-Za-z\\\\]+(?:Service|Repository|Manager)[A-Za-z]*)\s*;/', $content, $m);
            foreach ($m[1] as $className) {
                $shortName = class_basename(str_replace('\\', '/', $className));
                $file      = $this->findFileByClassName($projectRoot, $shortName);
                if ($file) {
                    $serviceFiles[$file] = file_get_contents($projectRoot . '/' . $file);
                }
            }
        }

        return $serviceFiles;
    }

    private function findFileByClassName(string $projectRoot, string $className): ?string
    {
        $searchDirs = ['app', 'Modules', 'src'];

        foreach ($searchDirs as $dir) {
            $base = $projectRoot . '/' . $dir;
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php' &&
                    str_contains($file->getFilename(), $className)) {
                    return ltrim(str_replace($projectRoot, '', $file->getPathname()), '/');
                }
            }
        }

        return null;
    }

    private function buildSummary(array $files, string $projectType): array
    {
        $totalLines = 0;
        $totalSize  = 0;

        foreach ($files as $content) {
            $totalLines += substr_count($content, "\n") + 1;
            $totalSize  += strlen($content);
        }

        return [
            'project_type'  => $projectType,
            'total_files'   => count($files),
            'total_lines'   => $totalLines,
            'total_size_kb' => round($totalSize / 1024, 2),
            'file_list'     => array_keys($files),
        ];
    }
}
