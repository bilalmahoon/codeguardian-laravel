<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

use CodeGuardian\Laravel\Support\FileTypeDetector;

/**
 * Database & schema reviewer — focuses on migrations and Eloquent models.
 *
 * Catches the schema-level problems that the controller/service analyzers can't
 * see: irreversible migrations, unindexed foreign keys, money stored as float,
 * enum columns, and unguarded mass assignment on models.
 *
 * Every check is conservative (low false-positive) and operates per-line so the
 * reported location points at the exact column / statement.
 */
class DatabaseAnalyzer extends BaseAnalyzer
{
    public function getName(): string
    {
        return 'database';
    }

    public function analyze(array $files, ?callable $onFile = null): array
    {
        foreach ($files as $filePath => $content) {
            $this->tick($onFile, $filePath);

            if (! str_ends_with($filePath, '.php')) {
                continue;
            }

            if (FileTypeDetector::isMigration($filePath) || $this->looksLikeMigration($content)) {
                $this->checkMissingDown($filePath, $content);
                $this->checkUnindexedForeignKey($filePath, $content);
                $this->checkEnumColumn($filePath, $content);
                $this->checkMoneyAsFloat($filePath, $content);
                $this->checkUniqueLookupColumn($filePath, $content);
                continue;
            }

            if (FileTypeDetector::isModel($filePath, $content)) {
                $this->checkUnguardedModel($filePath, $content);
            }
        }

        $findings = $this->flushResults();
        $score    = $this->calculateScore($findings);

        return [
            'agent'          => $this->getName(),
            'database_score' => $score,
            'findings'       => $findings,
            'summary'        => $this->buildSummary($findings),
        ];
    }

    private function looksLikeMigration(string $content): bool
    {
        return (bool) preg_match('/extends\s+Migration\b/', $content)
            || (bool) preg_match('/Schema::(create|table)\s*\(/', $content);
    }

    /** An up() with no down() cannot be rolled back. */
    private function checkMissingDown(string $filePath, string $content): void
    {
        $hasUp   = (bool) preg_match('/function\s+up\s*\(/', $content);
        $hasDown = (bool) preg_match('/function\s+down\s*\(/', $content);

        // Anonymous-class migrations (Laravel 9+) and classic class migrations
        // both define up()/down(); only flag when up exists without down.
        if ($hasUp && ! $hasDown) {
            $line = $this->lineOf($content, '/function\s+up\s*\(/');
            $this->addResult(AnalysisResult::make(
                category:       'irreversible_migration',
                severity:       'medium',
                title:          'Migration has up() but no down()',
                description:    'This migration cannot be rolled back. `php artisan migrate:rollback` will fail or leave the schema in an inconsistent state.',
                file:           $filePath,
                lineStart:      $line,
                lineEnd:        $line,
                recommendation: 'Add a down() method that reverses every change made in up() (drop created tables/columns, restore dropped ones).',
                codeBefore:     "public function up() { Schema::create('orders', ...); }",
                codeAfter:      "public function up() { Schema::create('orders', ...); }\npublic function down() { Schema::dropIfExists('orders'); }",
                confidence:     'high',
                impact:         'Blocks safe rollbacks during deploys and local resets.',
                effort:         'trivial',
                breakingRisk:   'none',
                rootCause:      'Reverse migration not implemented.',
                principle:      'Reliability: reversible migrations',
            ));
        }
    }

    /** A *_id column declared without a foreign key constraint or index. */
    private function checkUnindexedForeignKey(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        $hasConstrainedSomewhere = str_contains($content, '->constrained(')
            || preg_match('/\$table->foreign\s*\(/', $content);

        foreach ($lines as $i => $line) {
            if ($this->isComment($line)) {
                continue;
            }

            // e.g. $table->unsignedBigInteger('user_id'); / $table->bigInteger('post_id')
            if (! preg_match('/\$table->(?:unsignedBigInteger|bigInteger|unsignedInteger|integer|foreignId)\s*\(\s*[\'"](\w*_id)[\'"]/', $line, $m)) {
                continue;
            }

            $col      = $m[1];
            $window   = implode("\n", array_slice($lines, max(0, $i - 1), 4));
            $indexed  = str_contains($line, '->constrained(')
                || str_contains($line, '->index(')
                || str_contains($line, '->unique(')
                || preg_match('/\$table->(?:foreign|index|unique)\s*\(\s*[\'"]' . preg_quote($col, '/') . '[\'"]/', $window)
                || ($hasConstrainedSomewhere && str_contains($line, 'foreignId'));

            if (! $indexed) {
                $this->addResult(AnalysisResult::make(
                    category:       'unindexed_foreign_key',
                    severity:       'high',
                    title:          "Foreign key '{$col}' has no index or constraint",
                    description:    "Line " . ($i + 1) . ": '{$col}' looks like a foreign key but has no index or foreign-key constraint. Joins and lookups on it cause full table scans, and orphaned rows are not prevented.",
                    file:           $filePath,
                    lineStart:      $i + 1,
                    lineEnd:        $i + 1,
                    codeSnippet:    trim($line),
                    recommendation: "Use \$table->foreignId('{$col}')->constrained(), or add \$table->index('{$col}') plus a foreign key.",
                    codeBefore:     "\$table->unsignedBigInteger('{$col}');",
                    codeAfter:      "\$table->foreignId('{$col}')->constrained()->cascadeOnDelete();",
                    confidence:     'medium',
                    impact:         'Slow joins on large tables and no referential integrity.',
                    effort:         'small',
                    breakingRisk:   'low',
                    rootCause:      'FK column declared without index/constraint.',
                    principle:      'Database: index foreign keys',
                ));
            }
        }
    }

    /** enum() columns are painful to change — every value change needs a migration. */
    private function checkEnumColumn(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if ($this->isComment($line)) {
                continue;
            }
            if (preg_match('/\$table->enum\s*\(/', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'enum_column',
                    severity:       'low',
                    title:          'enum column is hard to evolve',
                    description:    'Line ' . ($i + 1) . ': enum columns require an ALTER TABLE (and a migration) to add or change a value, and behave differently across databases.',
                    file:           $filePath,
                    lineStart:      $i + 1,
                    lineEnd:        $i + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Prefer a string column validated in the application, or a lookup table with a foreign key.',
                    codeBefore:     "\$table->enum('status', ['active', 'inactive']);",
                    codeAfter:      "\$table->string('status')->default('active'); // validate allowed values in the app",
                    confidence:     'medium',
                    impact:         'Schema changes needed for every new status/value.',
                    effort:         'small',
                    breakingRisk:   'low',
                    rootCause:      'Closed value set encoded in the schema.',
                    principle:      'Maintainability: avoid enum columns',
                ));
            }
        }
    }

    /** Money stored as float/double loses precision. */
    private function checkMoneyAsFloat(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if ($this->isComment($line)) {
                continue;
            }
            if (preg_match('/\$table->(?:float|double)\s*\(\s*[\'"](\w*(?:price|amount|total|balance|cost|fee|salary|money|payment)\w*)[\'"]/i', $line, $m)) {
                $col = $m[1];
                $this->addResult(AnalysisResult::make(
                    category:       'money_as_float',
                    severity:       'high',
                    title:          "Monetary column '{$col}' uses float/double",
                    description:    "Line " . ($i + 1) . ": '{$col}' stores money as a binary float, which cannot represent decimal values exactly. Sums and comparisons will drift by fractions of a cent.",
                    file:           $filePath,
                    lineStart:      $i + 1,
                    lineEnd:        $i + 1,
                    codeSnippet:    trim($line),
                    recommendation: "Use \$table->decimal('{$col}', 10, 2) (or store integer minor units / cents).",
                    codeBefore:     "\$table->float('{$col}');",
                    codeAfter:      "\$table->decimal('{$col}', 12, 2);",
                    confidence:     'high',
                    impact:         'Rounding errors in financial calculations.',
                    effort:         'small',
                    breakingRisk:   'medium',
                    rootCause:      'Floating-point type used for exact decimal data.',
                    principle:      'Correctness: money as decimal',
                ));
            }
        }
    }

    /** Columns clearly meant to be unique lookups (email/slug/uuid/token) with no unique index. */
    private function checkUniqueLookupColumn(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if ($this->isComment($line)) {
                continue;
            }
            if (preg_match('/\$table->string\s*\(\s*[\'"](email|slug|uuid|username|token|api_token)[\'"]/', $line, $m)) {
                $col    = $m[1];
                $window = implode("\n", array_slice($lines, max(0, $i - 1), 4));
                $hasUnique = str_contains($line, '->unique(')
                    || preg_match('/\$table->unique\s*\(\s*[\'"]' . preg_quote($col, '/') . '[\'"]/', $window);
                if (! $hasUnique) {
                    $this->addResult(AnalysisResult::make(
                        category:       'missing_unique_index',
                        severity:       'medium',
                        title:          "Lookup column '{$col}' has no unique index",
                        description:    "Line " . ($i + 1) . ": '{$col}' is typically looked up and must be unique, but no unique index is declared. Duplicate values can be inserted and lookups stay slow.",
                        file:           $filePath,
                        lineStart:      $i + 1,
                        lineEnd:        $i + 1,
                        codeSnippet:    trim($line),
                        recommendation: "Add ->unique(): \$table->string('{$col}')->unique();",
                        codeBefore:     "\$table->string('{$col}');",
                        codeAfter:      "\$table->string('{$col}')->unique();",
                        confidence:     'medium',
                        impact:         'Duplicate rows and slow lookups.',
                        effort:         'trivial',
                        breakingRisk:   'low',
                        rootCause:      'Unique lookup column without a unique index.',
                        principle:      'Database: unique lookup columns',
                    ));
                }
            }
        }
    }

    /** Model with `$guarded = []` opens every attribute to mass assignment. */
    private function checkUnguardedModel(string $filePath, string $content): void
    {
        if (preg_match('/protected\s+\$guarded\s*=\s*\[\s*\]\s*;/', $content)) {
            $line = $this->lineOf($content, '/protected\s+\$guarded\s*=\s*\[\s*\]/');
            $this->addResult(AnalysisResult::make(
                category:       'unguarded_model',
                severity:       'high',
                title:          'Model is fully unguarded ($guarded = [])',
                description:    'Every column — including id, role, is_admin, balance — can be mass-assigned from request input. A crafted request can overwrite privileged fields.',
                file:           $filePath,
                lineStart:      $line,
                lineEnd:        $line,
                codeSnippet:    'protected $guarded = [];',
                recommendation: 'Declare an explicit $fillable allow-list of user-settable columns instead.',
                codeBefore:     'protected $guarded = [];',
                codeAfter:      "protected \$fillable = ['name', 'email'];",
                confidence:     'high',
                impact:         'Privilege escalation / data tampering via mass assignment.',
                effort:         'small',
                breakingRisk:   'low',
                rootCause:      'No mass-assignment allow-list on the model.',
                cwe:            'CWE-915',
                owasp:          'A08:2021-Software and Data Integrity Failures',
                principle:      'Security: mass-assignment allow-list',
            ));
            return;
        }

        // No $fillable and no $guarded at all → defaults still block, but it's a smell.
        $hasFillable = (bool) preg_match('/protected\s+\$fillable\s*=/', $content);
        $hasGuarded  = (bool) preg_match('/protected\s+\$guarded\s*=/', $content);
        $isEloquent  = (bool) preg_match('/extends\s+(Model|Authenticatable|Eloquent)\b/', $content);

        if ($isEloquent && ! $hasFillable && ! $hasGuarded) {
            $line = $this->lineOf($content, '/class\s+\w+/');
            $this->addResult(AnalysisResult::make(
                category:       'no_mass_assignment_policy',
                severity:       'low',
                title:          'Model declares neither $fillable nor $guarded',
                description:    'The model relies on the framework default. Make the mass-assignment policy explicit so reviewers can see which columns are user-settable.',
                file:           $filePath,
                lineStart:      $line,
                lineEnd:        $line,
                recommendation: "Add an explicit \$fillable allow-list of the columns that may be mass-assigned.",
                confidence:     'low',
                impact:         'Ambiguous mass-assignment policy.',
                effort:         'trivial',
                breakingRisk:   'none',
                rootCause:      'Implicit mass-assignment configuration.',
                principle:      'Clarity: explicit fillable',
            ));
        }
    }

    private function lineOf(string $content, string $pattern): int
    {
        if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
            return substr_count(substr($content, 0, $m[0][1]), "\n") + 1;
        }
        return 1;
    }

    private function isComment(string $line): bool
    {
        $t = ltrim($line);
        return str_starts_with($t, '//') || str_starts_with($t, '#') || str_starts_with($t, '*');
    }
}
