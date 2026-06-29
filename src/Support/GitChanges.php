<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use Symfony\Component\Process\Process;

/**
 * Resolves the set of changed files from git, for incremental analysis
 * (--changed / --since). Keeps the git interaction isolated and the path
 * matching pure + testable.
 */
final class GitChanges
{
    /** Absolute path to the git repo root containing $dir, or null. */
    public static function repoRoot(string $dir): ?string
    {
        $out = self::git($dir, ['rev-parse', '--show-toplevel']);
        return $out[0] ?? null;
    }

    /**
     * Files changed in the working tree vs HEAD (staged + unstaged + untracked).
     *
     * @return array<int,string>|null  null when git is unavailable / not a repo
     */
    public static function workingTree(string $projectRoot): ?array
    {
        $tracked = self::git($projectRoot, ['diff', '--name-only', '--diff-filter=d', 'HEAD']);
        if ($tracked === null) {
            return null;
        }
        $untracked = self::git($projectRoot, ['ls-files', '--others', '--exclude-standard']) ?? [];

        return self::normalise(array_merge($tracked, $untracked));
    }

    /**
     * Files changed since a given ref (e.g. "main", "origin/main", "HEAD~5").
     *
     * @return array<int,string>|null  null when git/ref is unavailable
     */
    public static function since(string $projectRoot, string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }
        // Triple-dot: changes on our side since the merge-base — ideal for PRs.
        $changed = self::git($projectRoot, ['diff', '--name-only', '--diff-filter=d', $ref . '...HEAD']);
        if ($changed === null) {
            // Fall back to a two-dot diff (works when there's no common ancestor).
            $changed = self::git($projectRoot, ['diff', '--name-only', '--diff-filter=d', $ref]);
        }
        if ($changed === null) {
            return null;
        }

        // Include uncommitted edits too so local runs feel intuitive.
        $working = self::workingTree($projectRoot) ?? [];

        return self::normalise(array_merge($changed, $working));
    }

    /**
     * Intersect the scanned file map with a changed-file list. Matching is by
     * path suffix so it works whether the scan root is the repo root or a
     * subdirectory. Pure — no IO.
     *
     * @param array<string,string> $files    [relPath => content]
     * @param array<int,string>    $changed  repo-relative changed paths
     * @return array<string,string>
     */
    public static function filter(array $files, array $changed, string $projectRoot = '', string $scanPath = ''): array
    {
        if ($changed === []) {
            return [];
        }

        // If the scan path is a subdir of the repo, changed paths are
        // repo-relative; map the scan file keys to repo-relative for comparison.
        $prefix = '';
        if ($projectRoot !== '' && $scanPath !== '') {
            $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
            $scan = rtrim(str_replace('\\', '/', $scanPath), '/');
            if ($scan !== $root && str_starts_with($scan . '/', $root . '/')) {
                $prefix = ltrim(substr($scan, strlen($root)), '/') . '/';
            }
        }

        $changedSet = array_flip(array_map(fn($p) => str_replace('\\', '/', $p), $changed));

        $kept = [];
        foreach ($files as $path => $content) {
            $candidate = $prefix . str_replace('\\', '/', $path);
            if (isset($changedSet[$candidate]) || self::suffixHit($changedSet, str_replace('\\', '/', $path))) {
                $kept[$path] = $content;
            }
        }

        return $kept;
    }

    /** @param array<string,int> $changedSet */
    private static function suffixHit(array $changedSet, string $path): bool
    {
        foreach ($changedSet as $changed => $_) {
            if ($changed === $path || str_ends_with($changed, '/' . $path) || str_ends_with($path, '/' . $changed)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,string> $args
     * @return array<int,string>|null
     */
    private static function git(string $projectRoot, array $args): ?array
    {
        $process = new Process(array_merge(['git'], $args), $projectRoot);
        $process->run();
        if (! $process->isSuccessful()) {
            return null;
        }
        return self::normalise(explode("\n", $process->getOutput()));
    }

    /**
     * @param array<int,string> $list
     * @return array<int,string>
     */
    private static function normalise(array $list): array
    {
        $out = [];
        foreach ($list as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[$line] = true;
            }
        }
        return array_keys($out);
    }
}
