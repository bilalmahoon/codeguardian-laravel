<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use Symfony\Component\Process\Process;

/**
 * Reads and parses unified git diffs so the AI reviewer can focus on the exact
 * lines that changed — far cheaper and more relevant than re-reviewing whole
 * files. The parser is pure and unit-testable; fetching shells out to git.
 */
final class GitDiff
{
    /**
     * Run `git diff` and return the unified patch text, or null on failure.
     * With $ref, diffs against that ref; otherwise diffs the working tree
     * (uncommitted changes), including staged.
     */
    public static function fetch(string $repoRoot, ?string $ref = null): ?string
    {
        $args = ['git', 'diff', '--unified=3', '--no-color'];
        if ($ref !== null && $ref !== '') {
            $args[] = $ref;
        } else {
            $args[] = 'HEAD';
        }

        $process = new Process($args, $repoRoot);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        return $process->getOutput();
    }

    /**
     * Parse a unified diff into per-file entries.
     *
     * @return array<string, array{added: list<string>, removed: list<string>, patch: string}>
     */
    public static function parseUnifiedDiff(string $diff): array
    {
        $files   = [];
        $current = null;
        $buffer  = [];

        $flush = function () use (&$files, &$current, &$buffer): void {
            if ($current !== null) {
                $patch = implode("\n", $buffer);
                $files[$current]['patch'] = $patch;
            }
        };

        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, 'diff --git')) {
                $flush();
                $buffer  = [$line];
                $current = self::pathFromDiffHeader($line);
                if ($current !== null) {
                    $files[$current] = ['added' => [], 'removed' => [], 'patch' => ''];
                }
                continue;
            }

            if ($current === null) {
                continue;
            }

            $buffer[] = $line;

            // Skip file-header metadata lines.
            if (str_starts_with($line, '+++') || str_starts_with($line, '---')
                || str_starts_with($line, 'index ') || str_starts_with($line, '@@')
                || str_starts_with($line, 'new file') || str_starts_with($line, 'deleted file')
                || str_starts_with($line, 'rename ') || str_starts_with($line, 'similarity ')) {
                continue;
            }

            if (str_starts_with($line, '+')) {
                $files[$current]['added'][] = substr($line, 1);
            } elseif (str_starts_with($line, '-')) {
                $files[$current]['removed'][] = substr($line, 1);
            }
        }

        $flush();

        return $files;
    }

    /** Extract the post-image path ("b/...") from a `diff --git` header. */
    public static function pathFromDiffHeader(string $line): ?string
    {
        if (preg_match('#^diff --git a/(.+?) b/(.+)$#', $line, $m)) {
            return $m[2];
        }
        return null;
    }

    /**
     * Render a compact, AI-friendly summary of the diff: per file, the added and
     * removed lines only. Keeps prompts small and focused.
     *
     * @param array<string, array{added: list<string>, removed: list<string>, patch: string}> $parsed
     */
    public static function toReviewContext(array $parsed, int $maxLinesPerFile = 200): string
    {
        $out = [];
        foreach ($parsed as $file => $info) {
            $out[] = "### {$file}";
            $lines = 0;
            foreach ($info['removed'] as $r) {
                $out[] = '- ' . $r;
                if (++$lines >= $maxLinesPerFile) { break; }
            }
            foreach ($info['added'] as $a) {
                $out[] = '+ ' . $a;
                if (++$lines >= $maxLinesPerFile) { break; }
            }
            $out[] = '';
        }
        return trim(implode("\n", $out));
    }
}
