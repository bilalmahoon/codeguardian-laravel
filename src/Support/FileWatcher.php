<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Lightweight, dependency-free file watcher based on mtime snapshots. Used by
 * codeguardian:watch to re-analyze only what changed. The snapshot/diff logic
 * is pure and unit-testable; the polling loop lives in the command.
 */
final class FileWatcher
{
    /**
     * @param list<string> $extensions File extensions to track (no dot).
     * @param list<string> $skipDirs   Directory names to skip entirely.
     */
    public function __construct(
        private array $extensions = ['php'],
        private array $skipDirs = ['vendor', 'node_modules', '.git', 'storage', 'bootstrap'],
    ) {}

    /**
     * Build a map of [absolutePath => mtime] for all tracked files under $root.
     *
     * @return array<string,int>
     */
    public function snapshot(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return ! in_array($current->getFilename(), $this->skipDirs, true);
                    }
                    return true;
                }
            )
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            if (! in_array(strtolower($file->getExtension()), $this->extensions, true)) {
                continue;
            }
            $snapshot[$file->getPathname()] = (int) $file->getMTime();
        }

        return $snapshot;
    }

    /**
     * Compare two snapshots.
     *
     * @param  array<string,int> $old
     * @param  array<string,int> $new
     * @return array{added: list<string>, modified: list<string>, removed: list<string>}
     */
    public static function diff(array $old, array $new): array
    {
        $added = $modified = $removed = [];

        foreach ($new as $path => $mtime) {
            if (! array_key_exists($path, $old)) {
                $added[] = $path;
            } elseif ($old[$path] !== $mtime) {
                $modified[] = $path;
            }
        }

        foreach (array_keys($old) as $path) {
            if (! array_key_exists($path, $new)) {
                $removed[] = $path;
            }
        }

        return ['added' => $added, 'modified' => $modified, 'removed' => $removed];
    }

    /**
     * Convenience: the list of files that were added or modified between two
     * snapshots (the set worth re-analyzing).
     *
     * @param  array<string,int> $old
     * @param  array<string,int> $new
     * @return list<string>
     */
    public static function changed(array $old, array $new): array
    {
        $d = self::diff($old, $new);
        return array_values(array_merge($d['added'], $d['modified']));
    }
}
