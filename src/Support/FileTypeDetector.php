<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Single source of truth for file-type detection.
 *
 * Problem solved: isController / isModel / isService logic was duplicated
 * between BaseAnalyzer and StaticTestGenerator, with subtly different rules.
 * All callers now share the same logic through this static utility.
 */
final class FileTypeDetector
{
    /** File belongs to the HTTP controller layer. */
    public static function isController(string $filePath, string $content = ''): bool
    {
        if (
            str_contains($filePath, 'Controller') ||
            str_contains($filePath, '/controllers/') ||
            str_contains($filePath, '/Controllers/')
        ) {
            return true;
        }

        // Content-based fallback: class extends Controller
        return $content !== '' && (bool) preg_match('/extends\s+\w*Controller\b/', $content);
    }

    /** File is an Eloquent model. */
    public static function isModel(string $filePath, string $content = ''): bool
    {
        if (
            str_contains($filePath, '/Models/') ||
            str_contains($filePath, '/Model/')
        ) {
            return true;
        }

        // Content-based fallback: class extends Model
        return $content !== '' && (bool) preg_match('/extends\s+(Eloquent|Model)\b/', $content);
    }

    /** File is a service / action / domain-service class. */
    public static function isService(string $filePath, string $content = ''): bool
    {
        if (
            str_contains($filePath, 'Service') ||
            str_contains($filePath, '/services/') ||
            str_contains($filePath, '/Services/') ||
            str_contains($filePath, '/Actions/') ||
            str_contains($filePath, 'Action')
        ) {
            return true;
        }

        return false;
    }

    /** File is a repository / data-access layer. */
    public static function isRepository(string $filePath): bool
    {
        return str_contains($filePath, 'Repository') ||
               str_contains($filePath, '/repositories/') ||
               str_contains($filePath, '/Repositories/');
    }

    /** File is a migration. */
    public static function isMigration(string $filePath): bool
    {
        return str_contains($filePath, '/migrations/') ||
               str_contains($filePath, 'database/migrations');
    }

    /** File is a seeder or factory. */
    public static function isSeederOrFactory(string $filePath): bool
    {
        return str_contains($filePath, '/seeders/') ||
               str_contains($filePath, '/Seeders/') ||
               str_contains($filePath, '/factories/') ||
               str_contains($filePath, '/Factories/') ||
               str_ends_with($filePath, 'Seeder.php') ||
               str_ends_with($filePath, 'Factory.php');
    }

    /** File is a test file. */
    public static function isTest(string $filePath): bool
    {
        return str_contains($filePath, '/tests/') ||
               str_contains($filePath, '/Tests/') ||
               str_ends_with($filePath, 'Test.php') ||
               str_ends_with($filePath, 'test.php');
    }

    /**
     * Return a human-readable type label for the given file.
     * Used by StaticTestGenerator to choose a test template.
     */
    public static function classify(string $filePath, string $content = ''): string
    {
        if (self::isController($filePath, $content)) {
            return 'controller';
        }

        if (self::isModel($filePath, $content)) {
            return 'model';
        }

        if (self::isService($filePath)) {
            return 'service';
        }

        if (self::isRepository($filePath)) {
            return 'repository';
        }

        return 'generic';
    }

    private function __construct() {}
}
