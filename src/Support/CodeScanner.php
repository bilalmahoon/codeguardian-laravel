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
        // config() requires a bootstrapped Laravel app. Wrap in try/catch so
        // this class can be instantiated in unit tests without a running container.
        try {
            $this->skipDirs    = config('codeguardian.analysis.skip_dirs', [
                'vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache',
                '.dart_tool', 'build', '.pub-cache',
            ]);
            $this->maxFileSize = config('codeguardian.analysis.max_file_size', 100_000);
        } catch (\Throwable) {
            $this->skipDirs    = [
                'vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache',
                '.dart_tool', 'build', '.pub-cache',
            ];
            $this->maxFileSize = 100_000;
        }
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
            throw new \InvalidArgumentException("Project at {$projectRoot} is not module-based.");
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
     * Build context for specific APIs (routes + their full call chain).
     *
     * Resolution strategy — two passes, most-accurate first:
     *
     *   PRIMARY (Router + Reflection):
     *     Uses Laravel's own Router to find the exact controller@method, then
     *     uses PHP's ReflectionClass to walk the constructor dependency chain
     *     (Controller → Service → Repository). Zero false-positives; no guessing.
     *
     *   FALLBACK (regex file parser):
     *     Used when the Laravel app is not fully bootstrapped (e.g. unit-test
     *     context). Parses route definition files with regex, then does a
     *     keyword-based file search as a last resort.
     */
    public function buildContextForApi(
        string $projectRoot,
        string $apiFilter
    ): array {
        $resolvedRoutes = [];
        $files          = [];

        // ── PRIMARY: Laravel Router → PHP Reflection ──────────────────────────
        // Laravel's Router is the authoritative source for which controller
        // handles a URI. ReflectionClass walks the constructor dependency chain
        // automatically. No regex; no keyword guessing; no false-positives.
        $resolver = new RouteResolver($projectRoot);
        $resolved = $resolver->resolve($apiFilter);

        if (! empty($resolved)) {
            $tracer  = new DependencyTracer($projectRoot);
            $classes = array_unique(array_column($resolved, 'class'));

            // Auto-detect module boundary from the route handler's file path.
            // If the handler lives in Modules/UserAuthentication/..., the tracer
            // will ONLY follow dependencies also inside Modules/UserAuthentication/.
            // This prevents cross-module contamination and protects global providers.
            $handlerFile = $resolved[0]['file'] ?? null;
            $moduleRoot  = $handlerFile ? $tracer->detectModuleRoot($handlerFile) : null;

            $files          = $tracer->trace($classes, maxDepth: 2, moduleRoot: $moduleRoot);
            $resolvedRoutes = $resolved;
        }

        // ── FALLBACK: regex-based file parser ────────────────────────────────
        // Only reached when the Router is unavailable (no bootstrapped Laravel app).
        if (empty($files)) {
            return $this->buildContextForApiViaRegex($projectRoot, $apiFilter);
        }

        $summary = $this->buildSummary($files, 'laravel');

        // Build per-file reasons so the banner can show WHY each file is in scope.
        // This is purely diagnostic — makes it trivial to spot wrong files.
        $fileReasons = [];
        foreach ($resolved as $r) {
            $relPath = ltrim(str_replace($projectRoot, '', $r['file']), '/');
            $fileReasons[$relPath] = "route handler ({$r['uri']} → {$r['method']})";
        }
        $handlerFiles = array_column($resolved, 'file');
        $handlerFiles = array_map(fn($f) => ltrim(str_replace($projectRoot, '', $f), '/'), $handlerFiles);
        foreach ($files as $relPath => $_) {
            if (! isset($fileReasons[$relPath])) {
                $fileReasons[$relPath] = 'constructor dependency (traced via Reflection)';
            }
        }

        return [
            'project_type'      => 'laravel',
            'project_name'      => basename($projectRoot) . " [{$apiFilter}]",
            'scan_path'         => $projectRoot,
            'api_filter'        => $apiFilter,
            'routes'            => $resolvedRoutes,
            'files'             => $files,
            'summary'           => $summary,
            'scope'             => 'api',
            'resolution_method' => 'router+reflection',
            'module_root'       => $moduleRoot,
            'file_reasons'      => $fileReasons,
        ];
    }

    /**
     * Regex-based fallback for buildContextForApi.
     * Used when the Laravel app is not bootstrapped (e.g. unit tests).
     * Parses route definition files with regex, then uses keyword search as a
     * last resort. Includes related services found via use-statement scanning.
     */
    private function buildContextForApiViaRegex(string $projectRoot, string $apiFilter): array
    {
        $extractor      = new RouteExtractor($projectRoot);
        $allRoutes      = $extractor->extractAll();
        $filteredRoutes = $extractor->filter($allRoutes, $apiFilter);

        if (empty($filteredRoutes)) {
            throw new \InvalidArgumentException(
                "No routes matching '{$apiFilter}' found in the project."
            );
        }

        $files         = [];
        $hasUnresolved = false;

        foreach ($filteredRoutes as $route) {
            $controllerFile = $extractor->resolveControllerFile($route);
            if ($controllerFile) {
                $fullPath = $projectRoot . '/' . $controllerFile;
                if (file_exists($fullPath) && filesize($fullPath) <= $this->maxFileSize) {
                    $files[$controllerFile] = file_get_contents($fullPath);
                }
            } else {
                $hasUnresolved = true;
            }
        }

        // Keyword fallback ONLY when direct resolution found zero files
        if (empty($files) && $hasUnresolved) {
            $keywords = $this->extractUriKeywords($apiFilter);
            if (! empty($keywords)) {
                $fallback = $this->findControllersByUriKeywords($projectRoot, $keywords);
                $files    = array_merge($fallback, $files);
            }
        }

        if (empty($files)) {
            throw new \InvalidArgumentException(
                "Routes matching '{$apiFilter}' were found, but their controller files " .
                "could not be located on disk.\n" .
                "Hint: the controller class name parsed from the route definition " .
                "was not found under app/, Modules/, or src/.\n" .
                "Check the route file and ensure the controller file exists in one of those directories."
            );
        }

        // Pull in service / repository files via use-statement scanning
        $files = array_merge($files, $this->findRelatedServices($projectRoot, $files));

        $summary = $this->buildSummary($files, 'laravel');

        $fileReasons = [];
        foreach (array_keys($files) as $relPath) {
            $fileReasons[$relPath] = 'regex fallback (Router unavailable)';
        }

        return [
            'project_type'      => 'laravel',
            'project_name'      => basename($projectRoot) . " [{$apiFilter}]",
            'scan_path'         => $projectRoot,
            'api_filter'        => $apiFilter,
            'routes'            => $filteredRoutes,
            'files'             => $files,
            'summary'           => $summary,
            'scope'             => 'api',
            'resolution_method' => 'regex_fallback',
            'file_reasons'      => $fileReasons,
        ];
    }

    /**
     * Return files for a single specific file path.
     */
    public function buildContextForFile(string $projectRoot, string $relFilePath): array
    {
        $relFilePath = ltrim($relFilePath, '/');
        $fullPath    = $projectRoot . '/' . $relFilePath;

        if (! file_exists($fullPath)) {
            throw new \InvalidArgumentException("File not found: {$fullPath}");
        }

        $content = file_get_contents($fullPath);
        $files   = [$relFilePath => $content];

        // ── Trace the file's dependency chain (same as the API flow) ─────────
        // If the file declares a resolvable class, walk its constructor chain via
        // Reflection so the AI receives the full call chain as read-only context.
        // The module boundary is auto-detected so we never pull in other modules.
        $moduleRoot = null;
        $fqcn       = $this->extractFqcn($content);

        if ($fqcn !== null && class_exists($fqcn)) {
            try {
                $tracer     = new DependencyTracer($projectRoot);
                $moduleRoot = $tracer->detectModuleRoot($fullPath);
                $traced     = $tracer->trace([$fqcn], maxDepth: 2, moduleRoot: $moduleRoot);
                // Keep the target file first; merge traced dependencies after it
                $files = array_merge($files, $traced);
            } catch (\Throwable) {
                // Reflection not available — fall back to use-statement scanning
                $files = array_merge($files, $this->findRelatedServices($projectRoot, $files));
            }
        } else {
            // Class can't be reflected (no app bootstrap, trait, helper, etc.)
            $files = array_merge($files, $this->findRelatedServices($projectRoot, $files));
        }

        $summary = $this->buildSummary($files, 'laravel');

        $fileReasons = [$relFilePath => 'target file (explicitly provided)'];
        foreach (array_keys($files) as $relPath) {
            if (! isset($fileReasons[$relPath])) {
                $fileReasons[$relPath] = 'constructor dependency (traced via Reflection)';
            }
        }

        return [
            'project_type'      => 'laravel',
            'project_name'      => basename($projectRoot) . " [{$relFilePath}]",
            'scan_path'         => $projectRoot,
            'target_file'       => $relFilePath,
            'files'             => $files,
            'summary'           => $summary,
            'scope'             => 'file',
            'resolution_method' => 'file+reflection',
            'module_root'       => $moduleRoot,
            'file_reasons'      => $fileReasons,
        ];
    }

    /**
     * Extract the fully-qualified class name declared in a PHP source string.
     * Returns null if no namespaced class/interface/trait/enum is found.
     */
    private function extractFqcn(string $content): ?string
    {
        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $content, $nsMatch)) {
            $namespace = trim($nsMatch[1]);
        }

        if (preg_match('/^\s*(?:final\s+|abstract\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $content, $clsMatch)) {
            $class = $clsMatch[1];
            return $namespace !== '' ? $namespace . '\\' . $class : $class;
        }

        return null;
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
     * Find service/repository files referenced by the given source files.
     *
     * Three detection strategies (applied in order, results merged):
     *   1. `use` statement: `use App\Services\AuthService;`
     *   2. Constructor parameter type-hint: `public function __construct(AuthService $svc)`
     *   3. Method parameter type-hint: `public function foo(UserRepository $repo)`
     *
     * One level of recursion: after finding direct dependencies of the controllers,
     * the same search runs on each service/repository file so that
     *   Controller → Service → Repository
     * is all pulled into scope automatically.
     */
    private function findRelatedServices(string $projectRoot, array $sourceFiles, int $depth = 0): array
    {
        // Stop after two hops (Controller → Service → Repository) to avoid runaway scanning.
        if ($depth > 1) {
            return [];
        }

        $serviceFiles = [];
        $alreadyFound = array_keys($sourceFiles);

        foreach ($sourceFiles as $content) {
            $classNames = [];

            // 1. Fully-qualified `use` statements with a service-like suffix
            preg_match_all(
                '/use\s+([A-Za-z\\\\]+(?:Service|Repository|Manager|Contract|Interface)[A-Za-z]*)\s*;/',
                $content,
                $useMatches
            );
            foreach ($useMatches[1] as $fqcn) {
                $classNames[] = class_basename(str_replace('\\', '/', $fqcn));
            }

            // 2. Constructor parameter type-hints
            //    Captures the block between `__construct(` and the closing `)`.
            if (preg_match('/function\s+__construct\s*\(([^)]*)\)/s', $content, $ctorMatch)) {
                preg_match_all(
                    '/\b([A-Za-z][A-Za-z0-9]*(?:Service|Repository|Manager|Contract|Interface))\s+\$/',
                    $ctorMatch[1],
                    $paramMatches
                );
                foreach ($paramMatches[1] as $shortName) {
                    $classNames[] = $shortName;
                }
            }

            // 3. Regular method parameter type-hints (excluding __construct already done above)
            preg_match_all(
                '/function\s+(?!__construct\b)\w+\s*\([^)]*\b([A-Za-z][A-Za-z0-9]*(?:Service|Repository|Manager))\s+\$/',
                $content,
                $methodParamMatches
            );
            foreach ($methodParamMatches[1] as $shortName) {
                $classNames[] = $shortName;
            }

            // Resolve each unique class name to a file on disk
            foreach (array_unique($classNames) as $shortName) {
                $file = $this->findFileByClassName($projectRoot, $shortName);
                if ($file && ! in_array($file, $alreadyFound, true) && ! isset($serviceFiles[$file])) {
                    $serviceFiles[$file] = file_get_contents($projectRoot . '/' . $file);
                    $alreadyFound[]      = $file;
                }
            }
        }

        // Recurse one level: find what the services themselves depend on
        if (! empty($serviceFiles)) {
            $deeper = $this->findRelatedServices($projectRoot, $serviceFiles, $depth + 1);
            foreach ($deeper as $path => $content) {
                if (! isset($serviceFiles[$path])) {
                    $serviceFiles[$path] = $content;
                }
            }
        }

        return $serviceFiles;
    }

    /**
     * Extract meaningful search keywords from a URI filter string.
     * Strips version segments (v1, v2, api) and returns the rest.
     *
     * "v1/auth/login" → ['auth', 'login']
     * "api/v2/users/profile" → ['users', 'profile']
     */
    private function extractUriKeywords(string $apiFilter): array
    {
        $segments = array_filter(
            explode('/', trim($apiFilter, '/')),
            fn($s) => $s !== '' && ! preg_match('/^v\d+$|^api$/i', $s)
        );
        return array_values($segments);
    }

    /**
     * Infrastructure directories that should never appear in API request-handler scope.
     * Files inside these directories are framework plumbing, not request handlers.
     */
    private const INFRASTRUCTURE_DIRS = [
        '/Providers/', '/Middleware/', '/Console/', '/Exceptions/',
        '/Broadcasting/', '/Listeners/', '/Events/', '/Jobs/',
        '/Mail/', '/Notifications/', '/Policies/', '/Rules/',
        '/Observers/', '/Casts/', '/Scopes/',
    ];

    /**
     * Search for controller/action files relevant to a given API filter.
     *
     * Matching is intentionally tight:
     *   - Infrastructure directories are always excluded (Providers, Middleware, etc.)
     *   - Only files that look like request handlers are candidates
     *     (path contains /Controller or filename ends with Controller/Action/Handler)
     *   - Keywords are matched against the FILE NAME only, not the full path.
     *     This prevents RegisterController from matching the filter "v1/auth/login"
     *     just because it lives in an /Auth/ directory.
     *
     * @param  string[] $keywords  e.g. ['auth', 'login'] from 'v1/auth/login'
     * @return array<string, string>  [ 'relative/path.php' => 'file contents' ]
     */
    private function findControllersByUriKeywords(string $projectRoot, array $keywords): array
    {
        $files      = [];
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
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $pathname = str_replace('\\', '/', $file->getPathname());

                // Skip infrastructure files — they handle framework concerns, not HTTP requests
                $isInfrastructure = false;
                foreach (self::INFRASTRUCTURE_DIRS as $infraDir) {
                    if (str_contains($pathname, $infraDir)) {
                        $isInfrastructure = true;
                        break;
                    }
                }
                if ($isInfrastructure) {
                    continue;
                }

                // Must look like a request handler (path or filename signals it)
                $filename = pathinfo($file->getFilename(), PATHINFO_FILENAME);

                $isHandlerPath = str_contains($pathname, '/Controller')
                    || str_contains($pathname, '/Actions/')
                    || str_contains($pathname, '/Handlers/')
                    || str_ends_with($filename, 'Controller')
                    || str_ends_with($filename, 'Action')
                    || str_ends_with($filename, 'Handler');

                if (! $isHandlerPath) {
                    continue;
                }

                // Match keywords against the FILE NAME only — never the directory path.
                // Example: RegisterController.php lives in /Auth/ but its FILENAME does
                // not contain "auth" or "login", so it correctly does NOT match
                // the filter "v1/auth/login". LoginController.php DOES contain "login".
                $lowerFilename = strtolower($filename);
                foreach ($keywords as $keyword) {
                    if (str_contains($lowerFilename, strtolower($keyword))) {
                        $relPath = ltrim(str_replace($projectRoot, '', $file->getPathname()), '/');
                        if ($file->getSize() <= $this->maxFileSize) {
                            $files[$relPath] = file_get_contents($file->getPathname());
                        }
                        break;
                    }
                }
            }
        }

        return $files;
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
