<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

class PerformanceAnalyzer extends BaseAnalyzer
{
    public function getName(): string
    {
        return 'performance';
    }

    public function analyze(array $files): array
    {
        foreach ($files as $filePath => $content) {
            $this->checkNPlusOne($filePath, $content);
            $this->checkMissingEagerLoading($filePath, $content);
            $this->checkSelectAll($filePath, $content);
            $this->checkMissingCaching($filePath, $content);
            $this->checkIneffcientCollectionUsage($filePath, $content);
            $this->checkMissingDatabaseIndexHints($filePath, $content);
            $this->checkChunking($filePath, $content);
        }

        $findings = $this->flushResults();
        $score    = $this->calculateScore($findings);

        return [
            'agent'             => $this->getName(),
            'performance_score' => $score,
            'findings'          => $findings,
            'summary'           => $this->buildSummary($findings),
        ];
    }

    private function checkNPlusOne(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);

        // Detect: relationship access inside a loop (foreach / while / for)
        $inLoop      = false;
        $loopDepth   = 0;
        $loopLine    = 0;
        $bracketDepth = 0;
        $loopBracket = [];

        $relationshipPattern = '/->(?:' . implode('|', [
            'user', 'users', 'post', 'posts', 'order', 'orders',
            'product', 'products', 'comment', 'comments', 'tag', 'tags',
            'category', 'categories', 'role', 'roles', 'permission', 'permissions',
            'profile', 'address', 'addresses', 'invoice', 'invoices',
        ]) . ')\s*(?:\(|\->)/i';

        foreach ($lines as $lineNum => $line) {
            $bracketDepth += substr_count($line, '{') - substr_count($line, '}');

            if (preg_match('/\b(?:foreach|while|for)\s*\(/', $line)) {
                $loopDepth++;
                $loopLine    = $lineNum + 1;
                $loopBracket[] = $bracketDepth;
                $inLoop      = true;
            }

            if ($inLoop && ! empty($loopBracket) && $bracketDepth < end($loopBracket)) {
                array_pop($loopBracket);
                $loopDepth = max(0, $loopDepth - 1);
                $inLoop    = $loopDepth > 0;
            }

            if ($inLoop && preg_match($relationshipPattern, $line)) {
                // Check if the file doesn't already have ->with() or eager loading
                $snippet = implode("\n", array_slice($lines, max(0, $lineNum - 5), 10));
                $hasEager = str_contains($snippet, '->with(') || str_contains($snippet, '->load(');

                if (! $hasEager) {
                    $this->addResult(AnalysisResult::make(
                        category:       'n_plus_one',
                        severity:       'high',
                        title:          'Potential N+1 query inside loop',
                        description:    "Line " . ($lineNum + 1) . ": Relationship accessed inside a loop without eager loading. This causes 1 query per iteration (N+1 problem), severely impacting performance.",
                        file:           $filePath,
                        lineStart:      $loopLine,
                        lineEnd:        $lineNum + 1,
                        codeSnippet:    trim($line),
                        recommendation: 'Use eager loading with ->with(\'relationshipName\') when fetching the collection.',
                        codeBefore:     "\$orders = Order::all();\nforeach (\$orders as \$order) {\n    echo \$order->user->name; // N+1!\n}",
                        codeAfter:      "\$orders = Order::with('user')->get();\nforeach (\$orders as \$order) {\n    echo \$order->user->name; // Only 2 queries!\n}",
                    ));
                    break; // one issue per file for N+1 to avoid noise
                }
            }
        }
    }

    private function checkMissingEagerLoading(string $filePath, string $content): void
    {
        // Look for ->all() or ->get() followed by relationship access in same scope
        if (preg_match('/::all\(\)/', $content) || preg_match('/->get\(\)/', $content)) {
            // Check if there are relationships being accessed without with()
            $hasWithClause = preg_match('/->with\s*\(/', $content);

            if (! $hasWithClause && preg_match('/\$\w+->(?:user|order|post|category|role|tag)\b/', $content)) {
                $this->addResult(AnalysisResult::make(
                    category:       'eager_loading',
                    severity:       'medium',
                    title:          'Collection fetched without eager loading relationships',
                    description:    'Records are fetched with ::all() or ->get() but relationships are accessed without eager loading via ->with(). Consider adding eager loading.',
                    file:           $filePath,
                    recommendation: "Add ->with(['relationship']) to your query to avoid N+1 queries.",
                    codeBefore:     "\$users = User::all();\n// ... later: \$user->posts->count()",
                    codeAfter:      "\$users = User::with('posts')->get();",
                ));
            }
        }
    }

    private function checkSelectAll(string $filePath, string $content): void
    {
        // Only flag ::all() in controllers and services — migrations, seeders,
        // factories, tests, and routes legitimately use ::all() without issue.
        $skip = ['migration', 'seeder', 'factory', 'test', 'Test', 'seed', 'Seed',
                 'database/', 'routes/', 'config/', 'lang/'];
        foreach ($skip as $s) {
            if (str_contains($filePath, $s)) {
                return;
            }
        }

        if (! $this->isController($filePath) && ! $this->isService($filePath)) {
            return;
        }

        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            if (preg_match('/\b\w+::all\s*\(\s*\)/', $line) && ! str_contains($line, '//')) {
                $this->addResult(AnalysisResult::make(
                    category:       'select_all',
                    severity:       'medium',
                    title:          'Model::all() fetches every record without pagination',
                    description:    "Line " . ($lineNum + 1) . ": Using ::all() fetches every record. For large datasets this exhausts memory and slows the application.",
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Use ->paginate(25) or ->take(100)->get().',
                    codeBefore:     'User::all()',
                    codeAfter:      'User::paginate(25)',
                ));
            }
        }
    }

    private function checkMissingCaching(string $filePath, string $content): void
    {
        // Check if there are expensive queries that could benefit from caching
        $hasExpensiveQuery = preg_match('/->with\s*\(\[/', $content) &&
                             preg_match('/->get\s*\(\)/', $content);

        $hasCaching = preg_match('/Cache::/', $content) ||
                      preg_match('/cache\s*\(\s*\)/', $content) ||
                      preg_match('/->remember\s*\(/', $content);

        if ($hasExpensiveQuery && ! $hasCaching && $this->isController($filePath)) {
            $this->addResult(AnalysisResult::make(
                category:       'missing_cache',
                severity:       'low',
                title:          'Complex query without caching',
                description:    'Controller performs complex queries with multiple eager-loaded relationships but no caching. Repeated identical queries hit the database unnecessarily.',
                file:           $filePath,
                recommendation: "Wrap stable queries in Cache::remember() for a TTL (e.g., 60 minutes).",
                codeBefore:     "\$categories = Category::with(['products', 'meta'])->get();",
                codeAfter:      "\$categories = Cache::remember('categories', 3600, fn() => Category::with(['products', 'meta'])->get());",
            ));
        }
    }

    private function checkIneffcientCollectionUsage(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            // count() on Eloquent relation — should use ->count() query instead
            if (preg_match('/count\s*\(\s*\$\w+->(?:load|with)/', $line) ||
                (preg_match('/count\s*\(\s*\$\w+/', $line) && str_contains($content, '->load('))) {
                continue; // skip complex patterns
            }

            // Calling count() on a collection that was fetched from DB
            if (preg_match('/\bcount\s*\(\s*\$\w+\s*\)/', $line) && ! str_contains($line, '//')) {
                // Check nearby code for DB query
                $nearbyCode = implode("\n", array_slice($lines, max(0, $lineNum - 3), 6));
                if (str_contains($nearbyCode, '->get()') || str_contains($nearbyCode, '::all()')) {
                    $this->addResult(AnalysisResult::make(
                        category:       'inefficient_count',
                        severity:       'medium',
                        title:          'count() on collection — use database-level count instead',
                        description:    "Line " . ($lineNum + 1) . ": Using PHP count() on a loaded Eloquent collection loads all records just to count them. Use ->count() query method instead.",
                        file:           $filePath,
                        lineStart:      $lineNum + 1,
                        lineEnd:        $lineNum + 1,
                        codeSnippet:    trim($line),
                        recommendation: 'Use Model::where(...)->count() to run COUNT(*) query directly.',
                        codeBefore:     "\$users = User::where('active', 1)->get();\n\$count = count(\$users);",
                        codeAfter:      "\$count = User::where('active', 1)->count(); // Single SQL COUNT(*)",
                    ));
                    break;
                }
            }
        }
    }

    private function checkMissingDatabaseIndexHints(string $filePath, string $content): void
    {
        // Only check in application code, not migrations themselves
        if (str_contains($filePath, 'migration') || str_contains($filePath, 'database/')) {
            return;
        }

        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            // Where clause on common non-indexed fields
            $unindexedFieldPatterns = [
                '/->where\s*\(\s*["\'](?:email|phone|mobile|status|type|created_at)["\']/',
            ];

            foreach ($unindexedFieldPatterns as $pattern) {
                if (preg_match($pattern, $line) && ! str_contains($line, '//')) {
                    $field = '';
                    preg_match('/->where\s*\(\s*["\'](\w+)["\']/', $line, $m);
                    $field = $m[1] ?? 'field';

                    $this->addResult(AnalysisResult::make(
                        category:       'missing_index',
                        severity:       'low',
                        title:          "Query on '{$field}' — verify database index exists",
                        description:    "Line " . ($lineNum + 1) . ": Querying on '{$field}' without a database index causes a full table scan on large datasets.",
                        file:           $filePath,
                        lineStart:      $lineNum + 1,
                        lineEnd:        $lineNum + 1,
                        codeSnippet:    trim($line),
                        recommendation: "Add an index: \$table->index('{$field}') in your migration.",
                        codeBefore:     "// Migration missing: \$table->index('{$field}');",
                        codeAfter:      "\$table->string('{$field}')->index(); // or \$table->index('{$field}');",
                    ));
                    break;
                }
            }
        }
    }

    private function checkChunking(string $filePath, string $content): void
    {
        // Check if there are large data exports without chunking
        if ((str_contains($content, 'Excel::') || str_contains($content, 'export')) &&
            str_contains($content, '::all()') &&
            ! str_contains($content, 'chunk') &&
            ! str_contains($content, 'cursor')) {
            $this->addResult(AnalysisResult::make(
                category:       'memory_usage',
                severity:       'high',
                title:          'Export/bulk operation without chunking',
                description:    'Exporting or processing large datasets with ::all() loads all records into memory at once. Use chunk() or cursor() to process in batches.',
                file:           $filePath,
                recommendation: "Use Model::chunk(1000, fn(\$items) => ...) or Model::cursor() for memory-efficient processing.",
                codeBefore:     "\$data = User::all(); // loads 100k records into RAM",
                codeAfter:      "User::chunk(500, function(\$users) use (\$export) {\n    foreach (\$users as \$user) { \$export->add(\$user); }\n});",
            ));
        }
    }

    protected function calculateScore(array $findings): int
    {
        $score   = 100;
        $weights = ['critical' => 20, 'high' => 12, 'medium' => 6, 'low' => 2];

        foreach ($findings as $f) {
            $score -= $weights[$f['severity']] ?? 0;
        }

        return max(0, min(100, $score));
    }

    protected function buildSummary(array $findings): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($findings as $f) {
            $counts[$f['severity']] = ($counts[$f['severity']] ?? 0) + 1;
        }
        return array_merge(['total_issues' => count($findings)], $counts);
    }
}
