<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

class TechDebtAnalyzer extends BaseAnalyzer
{
    public function getName(): string
    {
        return 'tech_debt';
    }

    public function analyze(array $files): array
    {
        // Pre-build a global block-hash index once — O(n) — so per-file
        // duplication checks don't need to re-scan all files themselves.
        $globalBlockHashes = $this->buildBlockHashIndex($files);

        foreach ($files as $filePath => $content) {
            $this->checkLargeClass($filePath, $content);
            $this->checkComplexMethods($filePath, $content);
            $this->checkDuplicatedCode($filePath, $content, $globalBlockHashes);
            $this->checkTodoFixme($filePath, $content);
            $this->checkDeadCode($filePath, $content);
            $this->checkMissingReturnTypes($filePath, $content);
            $this->checkMagicNumbers($filePath, $content);
            $this->checkDeepNesting($filePath, $content);
        }

        $findings = $this->flushResults();
        $score    = $this->calculateScore($findings);

        return [
            'agent'           => $this->getName(),
            'tech_debt_score' => $score,
            'findings'        => $findings,
            'summary'         => $this->buildSummary($findings),
        ];
    }

    private function checkLargeClass(string $filePath, string $content): void
    {
        $lines = $this->lineCount($content);

        $thresholds = [
            'controller' => [150, 'medium'],
            'service'    => [250, 'medium'],
            'model'      => [200, 'medium'],
            'default'    => [300, 'low'],
        ];

        $type = 'default';
        if ($this->isController($filePath)) $type = 'controller';
        elseif ($this->isService($filePath)) $type = 'service';
        elseif ($this->isModel($filePath)) $type = 'model';

        [$threshold, $baseSeverity] = $thresholds[$type];
        $severity = $lines > $threshold * 2 ? 'high' : $baseSeverity;

        if ($lines > $threshold) {
            $publicMethods  = preg_match_all('/public\s+function\s+\w+/', $content);
            $privateMethods = preg_match_all('/private\s+function\s+\w+/', $content);

            $this->addResult(AnalysisResult::make(
                category:       'large_class',
                severity:       $severity,
                title:          "Large class: {$this->baseName($filePath)} ({$lines} lines, {$publicMethods} public + {$privateMethods} private methods)",
                description:    "Class has {$lines} lines and " . ($publicMethods + $privateMethods) . " methods. Large classes indicate multiple responsibilities (Single Responsibility Principle violation). Each class should do one thing.",
                file:           $filePath,
                lineStart:      1,
                lineEnd:        $lines,
                recommendation: "Split {$this->baseName($filePath)} into focused classes. Group related methods into separate Services/Traits.",
            ));
        }
    }

    private function checkComplexMethods(string $filePath, string $content): void
    {
        preg_match_all(
            '/(?:public|private|protected)\s+function\s+(\w+)\s*\(([^)]{0,200})\)\s*(?::\s*\S+)?\s*\{/m',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $contentLines = explode("\n", $content);
        $totalLines   = count($contentLines);

        foreach ($matches[1] as $i => $methodMatch) {
            $methodName   = $methodMatch[0];
            $methodOffset = $matches[0][$i][1];
            $methodLine   = substr_count(substr($content, 0, $methodOffset), "\n") + 1;

            // Walk forward to find actual method end
            $depth   = 0;
            $endLine = $methodLine;
            $started = false;
            for ($ln = $methodLine - 1; $ln < $totalLines && $ln < $methodLine + 300; $ln++) {
                $l = $contentLines[$ln];
                $depth += substr_count($l, '{') - substr_count($l, '}');
                if (! $started && $depth > 0) $started = true;
                if ($started && $depth <= 0) { $endLine = $ln + 1; break; }
            }

            $methodBody = implode("\n", array_slice($contentLines, $methodLine - 1, $endLine - $methodLine + 1));
            $complexity = $this->cyclomaticComplexity($methodBody);

            if ($complexity >= 10) {
                $severity = $complexity >= 20 ? 'high' : ($complexity >= 15 ? 'medium' : 'low');
                $this->addResult(AnalysisResult::make(
                    category:       'high_complexity',
                    severity:       $severity,
                    title:          "High complexity: {$this->baseName($filePath)}::{$methodName}() (complexity: {$complexity})",
                    description:    "Method '{$methodName}' has cyclomatic complexity of {$complexity}. Ideal is under 10. High complexity means hard to test, maintain, and understand — every branch needs a test case.",
                    file:           $filePath,
                    lineStart:      $methodLine,
                    recommendation: "Break '{$methodName}' into smaller focused methods. Use early returns to reduce nesting. Consider a state machine or strategy pattern.",
                ));
            }
        }
    }

    /**
     * Build a map of { md5(block) => firstSeenFilePath } for all files.
     * Called once before the main loop so per-file checks are O(1) lookups.
     */
    private function buildBlockHashIndex(array $files): array
    {
        $blockSize = 5;
        $index     = []; // hash => first file path that contained it

        foreach ($files as $filePath => $content) {
            $lines = $this->meaningfulLines($content);
            if (count($lines) < $blockSize) {
                continue;
            }
            for ($i = 0; $i <= count($lines) - $blockSize; $i++) {
                $hash = md5(implode("\n", array_slice($lines, $i, $blockSize)));
                if (! isset($index[$hash])) {
                    $index[$hash] = $filePath;
                }
            }
        }

        return $index;
    }

    /**
     * O(n) per file — looks up hashes in the pre-built index.
     */
    private function checkDuplicatedCode(string $filePath, string $content, array $globalIndex): void
    {
        $blockSize = 5;
        $lines     = $this->meaningfulLines($content);

        if (count($lines) < $blockSize) {
            return;
        }

        $reported = false;
        for ($i = 0; $i <= count($lines) - $blockSize; $i++) {
            $block = implode("\n", array_slice($lines, $i, $blockSize));
            $hash  = md5($block);

            if (isset($globalIndex[$hash]) && $globalIndex[$hash] !== $filePath) {
                $otherFile = $globalIndex[$hash];
                $this->addResult(AnalysisResult::make(
                    category:       'duplication',
                    severity:       'medium',
                    title:          'Duplicated code block detected',
                    description:    "Identical code block (~{$blockSize} lines) found in:\n- " . basename($filePath) . "\n- " . basename($otherFile) . "\n\nDuplicated code means bug fixes must be applied in multiple places.",
                    file:           $filePath,
                    recommendation: 'Extract the duplicated logic into a shared Service, Trait, or Base class.',
                    codeSnippet:    $block,
                ));
                $reported = true;
                break; // One duplication report per file is enough
            }
        }
    }

    /** Return only meaningful lines (non-empty, non-comment, long enough). */
    private function meaningfulLines(string $content): array
    {
        $lines = array_map('trim', explode("\n", $content));
        return array_values(array_filter(
            $lines,
            fn($l) => strlen($l) > 20
                   && ! str_starts_with($l, '//')
                   && ! str_starts_with($l, '*')
                   && ! str_starts_with($l, '#')
        ));
    }

    private function checkTodoFixme(string $filePath, string $content): void
    {
        $lines   = explode("\n", $content);
        $count   = 0;
        $samples = [];

        foreach ($lines as $lineNum => $line) {
            if (preg_match('/\b(?:TODO|FIXME|HACK|XXX|BUG|TEMP|DEPRECATED)\b/i', $line)) {
                $count++;
                if (count($samples) < 3) {
                    $samples[] = ($lineNum + 1) . ': ' . trim($line);
                }
            }
        }

        if ($count === 0) {
            return;
        }

        $this->addResult(AnalysisResult::make(
            category:       'todo_debt',
            severity:       $count > 5 ? 'medium' : 'low',
            title:          "{$count} TODO/FIXME comment(s) in {$this->baseName($filePath)}",
            description:    "File contains {$count} unresolved TODO/FIXME markers, indicating acknowledged but unaddressed technical debt:\n" . implode("\n", $samples),
            file:           $filePath,
            recommendation: 'Address TODOs before release. Create proper tickets/issues for deferred work.',
        ));
    }

    private function checkDeadCode(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);

            // Commented-out code blocks (not documentation)
            if (preg_match('/^\s*\/\/\s*\$/', $line) ||
                preg_match('/^\s*\/\/\s*(?:return|if|foreach|echo|dd|dump)\s/', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'dead_code',
                    severity:       'low',
                    title:          'Commented-out code detected',
                    description:    "Line " . ($lineNum + 1) . ": Commented-out code is dead code. Version control (git) preserves history — remove it.",
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    $trimmed,
                    recommendation: 'Delete commented-out code. Use git log/blame to retrieve old code if needed.',
                ));
                break; // one per file
            }
        }
    }

    private function checkMissingReturnTypes(string $filePath, string $content): void
    {
        // Count methods without return type declarations (PHP 8.1 should have them)
        $totalMethods  = preg_match_all('/(?:public|private|protected)\s+function\s+\w+\s*\(/', $content);
        $typedMethods  = preg_match_all('/(?:public|private|protected)\s+function\s+\w+\s*\([^)]*\)\s*:\s*\S+/', $content);

        if ($totalMethods < 3) {
            return;
        }

        $untyped = $totalMethods - $typedMethods;
        if ($untyped <= 0) {
            return;
        }

        $ratio = $untyped / $totalMethods;

        if ($ratio > 0.3) {
            $this->addResult(AnalysisResult::make(
                category:       'missing_types',
                severity:       $ratio > 0.7 ? 'medium' : 'low',
                title:          "Missing return types: {$untyped}/{$totalMethods} methods in {$this->baseName($filePath)}",
                description:    "{$untyped} out of {$totalMethods} methods lack PHP return type declarations. Type declarations catch bugs early and improve IDE support.",
                file:           $filePath,
                recommendation: "Add return type declarations to all methods. Use PHP 8.1 types: string|int|array|void|null|Model.",
                codeBefore:     "public function getUser(\$id) { ... }",
                codeAfter:      "public function getUser(int \$id): User|null { ... }",
            ));
        }
    }

    private function checkMagicNumbers(string $filePath, string $content): void
    {
        // Skip migrations, config, routes, lang — magic numbers are expected there
        $skipPaths = ['migration', 'config/', 'routes/', 'lang/', 'database/', 'seeder', 'factory'];
        foreach ($skipPaths as $s) {
            if (str_contains($filePath, $s)) return;
        }

        $lines  = explode("\n", $content);
        $magic  = [];

        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                continue;
            }

            // Find numeric literals that look "unexplained"
            if (preg_match_all('/(?<!["\'\w\->])(?<!\$)\b([2-9][0-9]{1,4})\b(?!\s*\.)/', $line, $m)) {
                foreach ($m[1] as $num) {
                    $n = (int) $num;
                    // Skip well-known safe numbers
                    if (in_array($n, [200, 201, 204, 302, 400, 401, 403, 404, 422, 429, 500,
                                      503, 60, 24, 30, 90, 100, 128, 255, 1000, 1024, 3600])) {
                        continue;
                    }
                    $magic[] = ['line' => $lineNum + 1, 'number' => $num, 'context' => $trimmed];
                    break; // one per line
                }
            }
        }

        if (count($magic) < 4) {
            return; // Raise threshold — less noise
        }

        $sample = array_slice($magic, 0, 3);
        $desc   = implode("\n", array_map(fn($m) => "  Line {$m['line']}: {$m['number']} in '{$m['context']}'", $sample));

        $this->addResult(AnalysisResult::make(
            category:       'magic_numbers',
            severity:       'low',
            title:          count($magic) . " magic numbers in {$this->baseName($filePath)}",
            description:    "File contains " . count($magic) . " unexplained numeric literals:\n{$desc}",
            file:           $filePath,
            recommendation: "Extract magic numbers to named constants or config values.",
            codeBefore:     "if (\$attempts > 5) { sleep(300); }",
            codeAfter:      "const MAX_ATTEMPTS = 5;\nconst LOCKOUT_SECONDS = 300;\nif (\$attempts > self::MAX_ATTEMPTS) { sleep(self::LOCKOUT_SECONDS); }",
        ));
    }

    private function checkDeepNesting(string $filePath, string $content): void
    {
        $lines       = explode("\n", $content);
        $maxDepth    = 0;
        $depth       = 0;
        $maxDepthLine = 0;

        foreach ($lines as $lineNum => $line) {
            $opens  = substr_count($line, '{') - substr_count($line, '\'{\'' ) - substr_count($line, '"{"');
            $closes = substr_count($line, '}') - substr_count($line, '\'}\'') - substr_count($line, '"}"');
            $depth += $opens - $closes;

            if ($depth > $maxDepth) {
                $maxDepth     = $depth;
                $maxDepthLine = $lineNum + 1;
            }
        }

        // Class itself adds 1, method adds 1, so 5+ means 3+ levels of logic nesting
        if ($maxDepth >= 6) {
            $this->addResult(AnalysisResult::make(
                category:       'deep_nesting',
                severity:       $maxDepth >= 8 ? 'medium' : 'low',
                title:          "Deep nesting detected in {$this->baseName($filePath)} (depth: {$maxDepth})",
                description:    "Code reaches nesting depth of {$maxDepth} around line {$maxDepthLine}. Deep nesting makes code hard to read and test.",
                file:           $filePath,
                lineStart:      $maxDepthLine,
                recommendation: "Use early returns (guard clauses) to reduce nesting. Extract nested logic to private methods.",
                codeBefore:     "if (\$user) {\n    if (\$user->isActive()) {\n        if (\$user->hasPermission()) {\n            // do work...\n        }\n    }\n}",
                codeAfter:      "if (! \$user) return;\nif (! \$user->isActive()) return;\nif (! \$user->hasPermission()) return;\n// do work...",
            ));
        }
    }

    private function calculateScore(array $findings): int
    {
        $score   = 100;
        $weights = ['critical' => 20, 'high' => 10, 'medium' => 5, 'low' => 1];
        foreach ($findings as $f) {
            $score -= $weights[$f['severity']] ?? 0;
        }
        return max(0, min(100, $score));
    }

    private function buildSummary(array $findings): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($findings as $f) {
            $counts[$f['severity']] = ($counts[$f['severity']] ?? 0) + 1;
        }
        return array_merge(['total_issues' => count($findings)], $counts);
    }

    private function baseName(string $filePath): string
    {
        return basename($filePath, '.php');
    }
}
