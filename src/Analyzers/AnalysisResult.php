<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

/**
 * Represents a single finding from static analysis.
 *
 * The first 11 constructor arguments are the original, stable contract.
 * Everything after $codeAfter is OPTIONAL enrichment metadata (Phase 7 —
 * "actionable reports"): confidence, expected impact, estimated effort,
 * breaking-change risk, root cause, and security taxonomy (CWE / OWASP).
 * All new fields default to empty so existing callers keep working unchanged.
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
        // ── Enrichment metadata (all optional) ───────────────────────────────
        public readonly string  $confidence     = 'medium', // high | medium | low
        public readonly string  $impact         = '',       // expected impact of fixing
        public readonly string  $effort         = '',       // trivial | small | medium | large
        public readonly string  $breakingRisk   = '',       // none | low | medium | high
        public readonly string  $rootCause      = '',       // why this happens
        public readonly string  $cwe            = '',        // e.g. CWE-89
        public readonly string  $owasp          = '',        // e.g. A03:2021-Injection
        public readonly string  $principle      = '',        // e.g. SOLID:SRP, Clean Code
        /** @var array<int,string> */
        public readonly array   $references      = [],
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
            // Enrichment metadata
            'confidence'     => $this->confidence,
            'impact'         => $this->impact,
            'effort'         => $this->effort,
            'breaking_risk'  => $this->breakingRisk,
            'root_cause'     => $this->rootCause,
            'cwe'            => $this->cwe,
            'owasp'          => $this->owasp,
            'principle'      => $this->principle,
            'references'     => $this->references,
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
        string $confidence     = 'medium',
        string $impact         = '',
        string $effort         = '',
        string $breakingRisk   = '',
        string $rootCause      = '',
        string $cwe            = '',
        string $owasp          = '',
        string $principle      = '',
        array  $references      = [],
    ): self {
        return new self(
            $category, $severity, $title, $description, $file,
            $lineStart, $lineEnd, $codeSnippet, $recommendation, $codeBefore, $codeAfter,
            $confidence, $impact, $effort, $breakingRisk, $rootCause,
            $cwe, $owasp, $principle, $references,
        );
    }
}
