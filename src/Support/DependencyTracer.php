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
     * Trace the full dependency chain for the given FQCNs.
     *
     * @param  string[] $classes   Fully-qualified class names to start from (controllers)
     * @param  int      $maxDepth  Maximum hops to follow. 2 = Controller → Service → Repository.
     * @return array<string, string>  [ 'relative/path.php' => 'file contents' ]
     */
    public function trace(array $classes, int $maxDepth = 2): array
    {
        $this->visited = [];
        $files         = [];

        foreach ($classes as $class) {
            $this->traceClass($class, $files, 0, $maxDepth);
        }

        return $files;
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

        // Include only files inside the project — never vendor/
        if ($file && str_starts_with($file, $this->projectRoot)) {
            $relPath         = ltrim(str_replace($this->projectRoot, '', $file), '/');
            $files[$relPath] = file_get_contents($file);
        }

        // Stop recursing once we hit the depth cap
        if ($depth >= $maxDepth) {
            return;
        }

        $constructor = $ref->getConstructor();
        if (! $constructor) {
            return;
        }

        foreach ($constructor->getParameters() as $param) {
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
