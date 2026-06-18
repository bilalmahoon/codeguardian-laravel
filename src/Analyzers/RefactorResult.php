<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

/**
 * Value object representing the outcome of a single-file deterministic refactor operation.
 * Produced by StaticOrchestrator::refactorFile().
 */
final class RefactorResult
{
    public function __construct(
        public readonly string $filePath,
        public readonly string $original,
        public readonly string $refactored,
        public readonly array  $changes,
        public readonly int    $autoFixed,
        public readonly int    $manualTodos,
        /** @var array<string,string>  [ absolutePath => phpContent ] new files to create */
        public readonly array  $generatedFiles = [],
    ) {}

    /** True when the refactored content differs from the original. */
    public function hasChanges(): bool
    {
        return $this->original !== $this->refactored;
    }

    /**
     * Produce a simplified unified-diff-style string for display purposes.
     * Lines prefixed with '-' were removed/changed; '+' were added/changed.
     */
    public function diff(): string
    {
        $before = explode("\n", $this->original);
        $after  = explode("\n", $this->refactored);
        $diff   = [];

        $maxLen = max(count($before), count($after));

        for ($i = 0; $i < $maxLen; $i++) {
            $b = $before[$i] ?? null;
            $a = $after[$i]  ?? null;

            if ($b === $a) {
                continue;
            }

            if ($b !== null) {
                $diff[] = "- {$b}";
            }
            if ($a !== null) {
                $diff[] = "+ {$a}";
            }
        }

        return implode("\n", $diff);
    }

    public function toArray(): array
    {
        return [
            'file'            => $this->filePath,
            'has_changes'     => $this->hasChanges(),
            'auto_fixed'      => $this->autoFixed,
            'manual_todos'    => $this->manualTodos,
            'changes'         => $this->changes,
            'generated_files' => array_keys($this->generatedFiles),
        ];
    }
}
