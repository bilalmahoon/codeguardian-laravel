<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

/**
 * Flutter / Dart reviewer. Only inspects *.dart files, so it is harmless on a
 * pure-Laravel project (it simply emits nothing).
 *
 * Covers the highest-signal Flutter pitfalls: print() in production, setState()
 * inside build(), oversized build() methods, and using a BuildContext across an
 * async gap (the classic use_build_context_synchronously crash).
 */
class DartAnalyzer extends BaseAnalyzer
{
    public function getName(): string
    {
        return 'dart';
    }

    public function analyze(array $files, ?callable $onFile = null): array
    {
        foreach ($files as $filePath => $content) {
            $this->tick($onFile, $filePath);

            if (! str_ends_with($filePath, '.dart')) {
                continue;
            }

            $this->checkPrint($filePath, $content);
            $this->checkSetStateInBuild($filePath, $content);
            $this->checkLargeBuildMethod($filePath, $content);
            $this->checkContextAfterAwait($filePath, $content);
        }

        $findings = $this->flushResults();
        $score    = $this->calculateScore($findings);

        return [
            'agent'      => $this->getName(),
            'dart_score' => $score,
            'findings'   => $findings,
            'summary'    => $this->buildSummary($findings),
        ];
    }

    private function checkPrint(string $filePath, string $content): void
    {
        foreach (explode("\n", $content) as $i => $line) {
            if ($this->isComment($line)) {
                continue;
            }
            if (preg_match('/(?<![\w.])print\s*\(/', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'dart_print',
                    severity:       'low',
                    title:          'print() left in code',
                    description:    'Line ' . ($i + 1) . ': print() writes to stdout in release builds and cannot be filtered. Use a logger or debugPrint().',
                    file:           $filePath,
                    lineStart:      $i + 1,
                    lineEnd:        $i + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Replace print() with debugPrint() or a logging package (e.g. logger).',
                    codeBefore:     "print('value: \$value');",
                    codeAfter:      "debugPrint('value: \$value');",
                    confidence:     'high',
                    impact:         'Noisy, unfilterable logs in production.',
                    effort:         'trivial',
                    breakingRisk:   'none',
                    principle:      'Flutter: no print in production',
                ));
            }
        }
    }

    private function checkSetStateInBuild(string $filePath, string $content): void
    {
        $build = $this->buildMethod($content);
        if ($build === null) {
            return;
        }

        if (preg_match('/\bsetState\s*\(/', $build['body'])) {
            $this->addResult(AnalysisResult::make(
                category:       'setstate_in_build',
                severity:       'high',
                title:          'setState() called inside build()',
                description:    'Calling setState() during build() triggers an infinite rebuild loop and throws "setState() called during build".',
                file:           $filePath,
                lineStart:      $build['start_line'],
                lineEnd:        $build['end_line'],
                recommendation: 'Move state changes out of build() — into initState(), an event handler, or a post-frame callback.',
                codeBefore:     "Widget build(BuildContext context) {\n  setState(() => x++); // rebuild loop\n}",
                codeAfter:      "@override\nvoid initState() {\n  super.initState();\n  // set initial state here\n}",
                confidence:     'high',
                impact:         'Runtime crash / infinite rebuild.',
                effort:         'small',
                breakingRisk:   'low',
                principle:      'Flutter: no setState in build',
            ));
        }
    }

    private function checkLargeBuildMethod(string $filePath, string $content): void
    {
        $build = $this->buildMethod($content);
        if ($build === null) {
            return;
        }

        $lines = $build['end_line'] - $build['start_line'];
        if ($lines >= 80) {
            $this->addResult(AnalysisResult::make(
                category:       'large_build_method',
                severity:       'medium',
                title:          "Large build() method (~{$lines} lines)",
                description:    "build() is ~{$lines} lines. Deeply nested widget trees in one method are hard to read, reuse, and rebuild efficiently.",
                file:           $filePath,
                lineStart:      $build['start_line'],
                lineEnd:        $build['end_line'],
                recommendation: 'Extract sub-trees into small private widget classes (not helper methods) so Flutter can rebuild them independently.',
                confidence:     'medium',
                impact:         'Harder maintenance and coarser rebuilds.',
                effort:         'medium',
                breakingRisk:   'low',
                principle:      'Flutter: compose small widgets',
            ));
        }
    }

    /**
     * Using a BuildContext after an `await` without a mounted guard is the
     * use_build_context_synchronously lint — it can touch a disposed widget.
     */
    private function checkContextAfterAwait(string $filePath, string $content): void
    {
        $lines     = explode("\n", $content);
        $sawAwait  = false;
        $awaitLine = 0;

        foreach ($lines as $i => $line) {
            if ($this->isComment($line)) {
                continue;
            }

            // A mounted guard resets the danger window.
            if (preg_match('/\b(?:context\.)?mounted\b/', $line)) {
                $sawAwait = false;
            }

            if (preg_match('/\bawait\b/', $line)) {
                $sawAwait  = true;
                $awaitLine = $i + 1;
                continue;
            }

            if ($sawAwait && preg_match('/\b(?:Navigator|ScaffoldMessenger|Theme|MediaQuery|Provider|showDialog|showModalBottomSheet)\b[^;]*\bcontext\b/', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'context_after_await',
                    severity:       'high',
                    title:          'BuildContext used across an async gap',
                    description:    'Line ' . ($i + 1) . ': a BuildContext is used after an await (line ' . $awaitLine . ') without checking `mounted`. The widget may have been disposed, causing a runtime exception.',
                    file:           $filePath,
                    lineStart:      $i + 1,
                    lineEnd:        $i + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Guard with `if (!context.mounted) return;` (or check `mounted` on a State) immediately after the await.',
                    codeBefore:     "await save();\nNavigator.of(context).pop();",
                    codeAfter:      "await save();\nif (!context.mounted) return;\nNavigator.of(context).pop();",
                    confidence:     'medium',
                    impact:         'Crash when interacting with a disposed widget.',
                    effort:         'small',
                    breakingRisk:   'low',
                    principle:      'Flutter: guard context after await',
                ));
                $sawAwait = false; // one report per gap
            }
        }
    }

    /**
     * Locate the build() method and return its body + line range, or null.
     *
     * @return array{body:string,start_line:int,end_line:int}|null
     */
    private function buildMethod(string $content): ?array
    {
        if (! preg_match('/Widget\s+build\s*\(\s*BuildContext\s+\w+\s*\)\s*\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $offset    = $m[0][1];
        $startLine  = substr_count(substr($content, 0, $offset), "\n") + 1;
        $lines      = explode("\n", $content);
        $total      = count($lines);

        $depth   = 0;
        $started = false;
        $endLine = $startLine;

        for ($ln = $startLine - 1; $ln < $total; $ln++) {
            $line   = $lines[$ln];
            $depth += substr_count($line, '{') - substr_count($line, '}');
            if (! $started && $depth > 0) {
                $started = true;
            }
            if ($started && $depth <= 0) {
                $endLine = $ln + 1;
                break;
            }
        }

        $body = implode("\n", array_slice($lines, $startLine - 1, max(1, $endLine - $startLine + 1)));

        return ['body' => $body, 'start_line' => $startLine, 'end_line' => $endLine];
    }

    private function isComment(string $line): bool
    {
        $t = ltrim($line);
        return str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*');
    }
}
