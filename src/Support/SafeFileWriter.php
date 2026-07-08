<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Writes a file only if the new content passes validation, keeping a timestamped
 * backup and rolling back automatically on failure. Used by the Sentry auto-fix
 * flow so a bad AI rewrite can never leave a syntactically broken file on disk.
 *
 * The validator is injectable so the logic is unit-testable without shelling out
 * to `php -l`; by default it runs a real `php -l` syntax check.
 */
final class SafeFileWriter
{
    /** @var callable(string):(string|null) Returns an error string, or null when valid. */
    private $validator;

    /**
     * @param  null|callable(string):(string|null) $validator  Receives the absolute
     *         path of the candidate file; return null if OK or an error message.
     */
    public function __construct(?callable $validator = null)
    {
        $this->validator = $validator ?? [self::class, 'phpLintFile'];
    }

    /**
     * Attempt to write $content to $absPath. On validation failure the original
     * content is restored (or a newly-created file is removed).
     *
     * @return array{ok:bool,backup:?string,error:?string,created:bool}
     */
    public function write(string $absPath, string $content): array
    {
        $existed  = is_file($absPath);
        $original = $existed ? (string) @file_get_contents($absPath) : null;

        // No-op writes are a success but change nothing.
        if ($existed && $original === $content) {
            return ['ok' => true, 'backup' => null, 'error' => null, 'created' => false];
        }

        $backup = null;
        if ($existed) {
            $backup = $absPath . '.cg-bak.' . date('Ymd_His');
            @copy($absPath, $backup);
        } else {
            @mkdir(dirname($absPath), 0775, true);
        }

        if (@file_put_contents($absPath, $content) === false) {
            return ['ok' => false, 'backup' => $backup, 'error' => 'Could not write file (permissions?).', 'created' => false];
        }

        $error = ($this->validator)($absPath);
        if ($error !== null) {
            // Roll back.
            if ($existed && $original !== null) {
                @file_put_contents($absPath, $original);
            } elseif (! $existed) {
                @unlink($absPath);
            }
            return ['ok' => false, 'backup' => $backup, 'error' => $error, 'created' => false];
        }

        return ['ok' => true, 'backup' => $backup, 'error' => null, 'created' => ! $existed];
    }

    /** Restore a file from a backup produced by write(). */
    public function restore(string $absPath, string $backup): bool
    {
        if (! is_file($backup)) {
            return false;
        }
        return @copy($backup, $absPath) !== false;
    }

    /**
     * Default validator: run `php -l` on the file and return the parse error, or
     * null when the syntax is valid.
     */
    public static function phpLintFile(string $absPath): ?string
    {
        // Only PHP files are lint-checked; anything else is accepted as-is.
        if (strtolower((string) pathinfo($absPath, PATHINFO_EXTENSION)) !== 'php') {
            return null;
        }

        $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($absPath) . ' 2>&1';
        $out = (string) @shell_exec($cmd);

        if (str_contains($out, 'No syntax errors detected')) {
            return null;
        }

        $out = trim($out);
        return $out !== '' ? $out : 'php -l could not verify the file.';
    }
}
