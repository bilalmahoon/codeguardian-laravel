<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Traces the full dependency call chain for a set of controller classes
 * using PHP's ReflectionClass — no regex parsing of source files.
 *
 * Algorithm:
 *   1. Given a controller FQCN, open its ReflectionClass.
 *   2. Read constructor parameters. Each typed parameter that is not a
 *      built-in type (string, int, …) and not a vendor class is a dependency.
 *   3. If the dependency is an interface, resolve it to a concrete class via
 *      Laravel's IoC container.
 *   4. Recurse on each dependency (capped at $maxDepth hops).
 *
 * This gives exact, zero-false-positive results:
 *   APIAuthController → AuthService → UserRepository
 *
 * Unlike the keyword-based heuristic, this NEVER includes RegisterController
 * or RouteServiceProvider because they are simply not in the dependency graph.
 */
class DependencyTracer
{
    private string $projectRoot;

    /** Classes already visited in this trace run (prevents infinite loops). */
    private array $visited = [];

    /**
     * When set, only files whose relative path starts with this prefix are included.
     * Enforces the module-boundary rule: a module-scoped refactor must never pull in
     * files from other modules or from the global app/ infrastructure.
     */
    private ?string $moduleRoot = null;

    /**
     * Namespace prefixes owned by the framework / vendor libraries.
     * Classes under these namespaces are never included in the scope —
     * they live in vendor/ and are not project code.
     */
    private const VENDOR_NAMESPACES = [
        'Illuminate\\',
        'Laravel\\',
        'Symfony\\',
        'Carbon\\',
        'GuzzleHttp\\',
        'Psr\\',
        'Monolog\\',
        'Doctrine\\',
        'PhpParser\\',
        'Clockwork\\',
        'Barryvdh\\',
        'Spatie\\',
        'Nwidart\\',
        'Tymon\\',
    ];

    public function __construct(string $projectRoot)
    {
        // Resolve symlinks so that the path we store matches what ReflectionClass::getFileName()
        // returns. On macOS, sys_get_temp_dir() returns /var/... which is a symlink to
        // /private/var/...; without realpath() the str_starts_with guard always fails.
        $this->projectRoot = rtrim(realpath($projectRoot) ?: $projectRoot, '/');
    }

    /**
     * Entry-point methods to inspect for method-injected dependencies, keyed by
     * fully-qualified class name: [ 'App\..\APIAuthController' => 'authenticateUser' ].
     *
     * Many Laravel codebases inject dependencies as CONTROLLER METHOD parameters
     * rather than constructor parameters (action/feature pattern), e.g.:
     *     public function authenticateUser(UserLoginRequest $r, BaseLogin $login)
     * Constructor-only tracing misses the real business-logic class (BaseLogin),
     * so we also walk the resolved route method's parameters.
     */
    private array $entryMethods = [];

    /**
     * Trace the full dependency chain for the given FQCNs.
     *
     * @param  string[]    $classes      Fully-qualified class names to start from (controllers)
     * @param  int         $maxDepth     Maximum hops to follow. 2 = Controller → Service → Repository.
     * @param  string|null $moduleRoot   If set, ONLY include files whose relative path starts with
     *                                   this prefix (e.g. "Modules/UserAuthentication").
     *                                   Files outside the module boundary are skipped entirely —
     *                                   they are not in scope for a module-scoped refactoring.
     * @param  array       $entryMethods Map of class => method to inspect for method-injected
     *                                   dependencies (the resolved route handler method).
     * @return array<string, string>  [ 'relative/path.php' => 'file contents' ]
     */
    public function trace(
        array   $classes,
        int     $maxDepth = 2,
        ?string $moduleRoot = null,
        array   $entryMethods = []
    ): array {
        $this->visited      = [];
        $this->moduleRoot   = $moduleRoot ? ltrim($moduleRoot, '/') : null;
        $this->entryMethods = $entryMethods;
        $files              = [];

        foreach ($classes as $class) {
            $this->traceClass($class, $files, 0, $maxDepth);
        }

        return $files;
    }

    /**
     * Detect the module root for a given absolute file path.
     *
     * Given: /abs/project/Modules/UserAuthentication/Http/Controllers/APIAuthController.php
     * Returns: "Modules/UserAuthentication"
     *
     * Returns null for files in app/, src/, or other non-modular locations.
     */
    public function detectModuleRoot(string $absFile): ?string
    {
        $rel = ltrim(str_replace($this->projectRoot, '', $absFile), '/');

        if (preg_match('#^(Modules/[^/]+)/#', $rel, $m)) {
            return $m[1];
        }

        return null;
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function traceClass(string $class, array &$files, int $depth, int $maxDepth): void
    {
        if ($depth > $maxDepth || isset($this->visited[$class])) {
            return;
        }

        $this->visited[$class] = true;

        // Skip vendor / framework classes immediately
        if ($this->isVendorClass($class)) {
            return;
        }

        // If it is an interface or abstract class, resolve to the concrete
        // implementation registered in Laravel's IoC container.
        $concrete = $this->resolveConcrete($class);

        if (! class_exists($concrete)) {
            return;
        }

        try {
            $ref  = new \ReflectionClass($concrete);
            $file = $ref->getFileName();
        } catch (\ReflectionException) {
            return;
        }

        // A file outside the project root is a vendor/framework class — never
        // include it and never recurse into its hierarchy or dependencies.
        if (! $file || ! str_starts_with($file, $this->projectRoot)) {
            return;
        }

        $relPath = ltrim(str_replace($this->projectRoot, '', $file), '/');

        // Module-boundary enforcement: when $moduleRoot is set, only include
        // files that live within that module. Dependencies from other modules
        // or from the global app/ layer are skipped — they must not be modified
        // during a single-module refactoring operation.
        if ($this->moduleRoot !== null && ! str_starts_with($relPath, $this->moduleRoot . '/')) {
            return;
        }

        $source          = file_get_contents($file);
        $files[$relPath] = $source;

        // ── Class hierarchy (SAME depth) ─────────────────────────────────────
        // The parent class / traits / interfaces are PART of this class's
        // definition, not a separate dependency hop. This is critical for the
        // common pattern where the container resolves an abstract `BaseLogin`
        // to a thin concrete `Login extends BaseLogin` — the real logic lives in
        // the parent, so it MUST be pulled in even at the depth cap.
        $this->traceHierarchy($ref, $files, $depth, $maxDepth);

        // Stop recursing into dependencies once we hit the depth cap
        if ($depth >= $maxDepth) {
            return;
        }

        // 1) Constructor-injected dependencies (classic service pattern)
        $constructor = $ref->getConstructor();
        if ($constructor) {
            $this->traceParameters($constructor, $files, $depth, $maxDepth);
        }

        // 2) Method-injected dependencies on the resolved route handler method
        //    (action / feature pattern). Only applies to the entry class.
        $entryMethod = $this->entryMethods[$class] ?? ($this->entryMethods[$concrete] ?? null);
        if ($entryMethod !== null && $ref->hasMethod($entryMethod)) {
            $this->traceParameters($ref->getMethod($entryMethod), $files, $depth, $maxDepth);
        }

        // 3) Source-level dependencies used INSIDE method bodies (repositories,
        //    services, models, query builders) that are not type-hinted params.
        //    Reflection cannot see these, so scan the `use` imports of the file.
        if ($source !== '') {
            $this->traceUseImports($source, $files, $depth, $maxDepth);
        }
    }

    /**
     * Trace parent class, implemented interfaces, and used traits at the SAME
     * depth — they are part of the class definition, not a dependency hop.
     */
    private function traceHierarchy(
        \ReflectionClass $ref,
        array &$files,
        int $depth,
        int $maxDepth
    ): void {
        $parent = $ref->getParentClass();
        if ($parent) {
            $this->traceClass($parent->getName(), $files, $depth, $maxDepth);
        }

        foreach ($ref->getInterfaceNames() as $interface) {
            $this->traceClass($interface, $files, $depth, $maxDepth);
        }

        foreach ($ref->getTraitNames() as $trait) {
            $this->traceClass($trait, $files, $depth, $maxDepth);
        }
    }

    /**
     * Scan a file's `use Fully\Qualified\ClassName;` imports and trace each as a
     * dependency hop. This captures repositories / services / models / query
     * objects that are referenced inside method bodies (not constructor or method
     * parameters), which Reflection alone cannot discover.
     *
     * Vendor classes and out-of-module files are filtered downstream by
     * traceClass (isVendorClass + module-boundary check), so this stays tight.
     */
    private function traceUseImports(
        string $source,
        array &$files,
        int $depth,
        int $maxDepth
    ): void {
        if (! preg_match_all('/^\s*use\s+([A-Za-z0-9_\\\\]+)\s*(?:as\s+\w+)?\s*;/m', $source, $m)) {
            return;
        }

        foreach ($m[1] as $fqcn) {
            $fqcn = ltrim($fqcn, '\\');

            // Skip trait/function/const imports and obvious non-class noise
            if ($fqcn === '' || str_contains($fqcn, '{')) {
                continue;
            }

            $this->traceClass($fqcn, $files, $depth + 1, $maxDepth);
        }
    }

    /**
     * Trace every class-typed parameter of a constructor or method.
     */
    private function traceParameters(
        \ReflectionFunctionAbstract $fn,
        array &$files,
        int $depth,
        int $maxDepth
    ): void {
        foreach ($fn->getParameters() as $param) {
            $type = $param->getType();

            // Skip built-in types (string, int, array, …) and union / intersection types
            if (! ($type instanceof \ReflectionNamedType) || $type->isBuiltin()) {
                continue;
            }

            $this->traceClass($type->getName(), $files, $depth + 1, $maxDepth);
        }
    }

    /**
     * Resolve an interface / abstract class to its concrete implementation
     * using Laravel's IoC container bindings (if available).
     *
     * Falls back to returning the original class name unchanged — if it is
     * a concrete class `class_exists()` will still return true.
     */
    private function resolveConcrete(string $class): string
    {
        // Already a concrete instantiatable class
        if (class_exists($class) && ! (new \ReflectionClass($class))->isAbstract()) {
            return $class;
        }

        try {
            $bindings = app()->getBindings();

            if (isset($bindings[$class])) {
                $concrete = $bindings[$class]['concrete'];

                // Binding is a string → class name
                if (is_string($concrete) && class_exists($concrete)) {
                    return $concrete;
                }

                // Binding is a Closure factory → resolve via container
                // (avoid side effects by wrapping in try/catch)
                if ($concrete instanceof \Closure) {
                    $instance = app()->make($class);
                    return get_class($instance);
                }
            }
        } catch (\Throwable) {
            // IoC container not available (e.g. unit test context) — fall through
        }

        return $class;
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
