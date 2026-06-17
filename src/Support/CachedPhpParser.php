<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use PhpParser\Error;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * A thin wrapper around nikic/php-parser that creates the parser ONCE and
 * caches parse results in memory for the lifetime of the process.
 *
 * Problem solved: BaseAnalyzer::parse() was creating a new ParserFactory +
 * Parser object on every single call. On a 5000-file scan with 4 analyzers,
 * this meant 20,000 parser objects. Now we pay the construction cost once.
 *
 * Thread-safety: PHP is single-threaded per process, so a static cache is safe.
 */
final class CachedPhpParser
{
    private static ?Parser $parser = null;

    /** @var array<string, array|null>  hash => AST (null means parse failed) */
    private static array $cache = [];

    /**
     * Parse PHP source code, returning AST nodes.
     * Returns null when the source is not valid PHP.
     *
     * @return list<\PhpParser\Node\Stmt>|null
     */
    public static function parse(string $code): ?array
    {
        $hash = md5($code);

        if (array_key_exists($hash, self::$cache)) {
            return self::$cache[$hash];
        }

        $result = null;
        try {
            $result = self::getParser()->parse($code);
        } catch (Error) {
            // Intentionally swallow — null signals a parse failure to callers
        }

        self::$cache[$hash] = $result;
        return $result;
    }

    /** Clear the parse cache (useful in tests to prevent cross-test pollution). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function getParser(): Parser
    {
        if (self::$parser === null) {
            self::$parser = (new ParserFactory())->createForNewestSupportedVersion();
        }

        return self::$parser;
    }
}
