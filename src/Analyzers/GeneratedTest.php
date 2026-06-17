<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

/**
 * Value object representing a generated PHPUnit test file.
 * Produced by StaticTestGenerator::generateForFile().
 */
final class GeneratedTest
{
    public function __construct(
        public readonly string $className,
        public readonly string $filePath,
        public readonly string $content,
        public readonly string $sourceFile,
        public readonly array  $methodsCovered,
    ) {}

    public function lineCount(): int
    {
        return substr_count($this->content, "\n") + 1;
    }

    public function toArray(): array
    {
        return [
            'class'           => $this->className,
            'file'            => $this->filePath,
            'source'          => $this->sourceFile,
            'methods_covered' => $this->methodsCovered,
            'lines'           => $this->lineCount(),
        ];
    }
}
