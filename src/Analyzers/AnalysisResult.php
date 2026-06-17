<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

/**
 * Represents a single finding from static analysis.
 */
class AnalysisResult
{
    public function __construct(
        public readonly string  $category,
        public readonly string  $severity,      // critical | high | medium | low
        public readonly string  $title,
        public readonly string  $description,
        public readonly string  $file,
        public readonly int     $lineStart      = 0,
        public readonly int     $lineEnd        = 0,
        public readonly string  $codeSnippet    = '',
        public readonly string  $recommendation = '',
        public readonly string  $codeBefore     = '',
        public readonly string  $codeAfter      = '',
    ) {}

    public function toArray(): array
    {
        return [
            'category'       => $this->category,
            'severity'       => $this->severity,
            'title'          => $this->title,
            'description'    => $this->description,
            'file'           => $this->file,
            'line_start'     => $this->lineStart,
            'line_end'       => $this->lineEnd,
            'code_snippet'   => $this->codeSnippet,
            'recommendation' => $this->recommendation,
            'code_before'    => $this->codeBefore,
            'code_after'     => $this->codeAfter,
        ];
    }

    public static function make(
        string $category,
        string $severity,
        string $title,
        string $description,
        string $file,
        int    $lineStart      = 0,
        int    $lineEnd        = 0,
        string $codeSnippet    = '',
        string $recommendation = '',
        string $codeBefore     = '',
        string $codeAfter      = '',
    ): self {
        return new self(
            $category, $severity, $title, $description, $file,
            $lineStart, $lineEnd, $codeSnippet, $recommendation, $codeBefore, $codeAfter
        );
    }
}
