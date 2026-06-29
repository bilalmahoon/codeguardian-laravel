<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Maps changed source files to the tests that exercise them, so CI can run only
 * the relevant tests instead of the whole suite. Heuristic but effective: a test
 * is "impacted" if it references the changed class by name (use/new/static call)
 * or follows the conventional {Class}Test naming. Pure + unit-testable.
 */
final class TestImpact
{
    /** Class name from a PHP file path (basename without extension). */
    public static function classNameOf(string $path): string
    {
        $base = basename($path);
        return preg_replace('/\.php$/', '', $base) ?? $base;
    }

    /**
     * Resolve which test files are impacted by the changed source files.
     *
     * @param  list<string>          $changedFiles  changed source paths
     * @param  array<string,string>  $testFiles     [testPath => content]
     * @return list<string>          impacted test file paths (sorted, unique)
     */
    public static function impactedTests(array $changedFiles, array $testFiles): array
    {
        $impacted = [];

        // Changed test files are always impacted (they were edited).
        foreach ($changedFiles as $changed) {
            if (self::isTestPath($changed)) {
                $impacted[$changed] = true;
            }
        }

        // Derive class names of changed non-test source files.
        $classNames = [];
        foreach ($changedFiles as $changed) {
            if (self::isTestPath($changed) || ! str_ends_with($changed, '.php')) {
                continue;
            }
            $class = self::classNameOf($changed);
            if ($class !== '') {
                $classNames[$class] = true;
            }
        }

        if ($classNames !== []) {
            foreach ($testFiles as $testPath => $content) {
                foreach (array_keys($classNames) as $class) {
                    // Conventional {Class}Test file, or a reference to the class.
                    if (self::classNameOf((string) $testPath) === $class . 'Test'
                        || preg_match('/\b' . preg_quote($class, '/') . '\b/', (string) $content)) {
                        $impacted[(string) $testPath] = true;
                        break;
                    }
                }
            }
        }

        $paths = array_keys($impacted);
        sort($paths);
        return $paths;
    }

    /**
     * Build a PHPUnit --filter expression (regex of test class names) for the
     * impacted test files, or '' when there are none.
     *
     * @param list<string> $testFiles
     */
    public static function phpunitFilter(array $testFiles): string
    {
        $classes = [];
        foreach ($testFiles as $path) {
            $class = self::classNameOf($path);
            if ($class !== '') {
                $classes[$class] = true;
            }
        }
        if ($classes === []) {
            return '';
        }
        return implode('|', array_keys($classes));
    }

    private static function isTestPath(string $path): bool
    {
        return str_contains($path, '/tests/')
            || str_contains($path, '/Tests/')
            || str_ends_with($path, 'Test.php');
    }
}
