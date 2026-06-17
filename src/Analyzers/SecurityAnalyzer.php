<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

class SecurityAnalyzer extends BaseAnalyzer
{
    public function getName(): string
    {
        return 'security';
    }

    public function analyze(array $files): array
    {
        foreach ($files as $filePath => $content) {
            $this->checkSqlInjection($filePath, $content);
            $this->checkHardcodedSecrets($filePath, $content);
            $this->checkMissingAuthorization($filePath, $content);
            $this->checkMassAssignment($filePath, $content);
            $this->checkInsecureDirectOutput($filePath, $content);
            $this->checkMissingCsrfExemption($filePath, $content);
            $this->checkDebugCodeLeft($filePath, $content);
            $this->checkInsecureFileUpload($filePath, $content);
        }

        $findings = $this->flushResults();
        $score    = $this->calculateScore($findings);

        return [
            'agent'          => $this->getName(),
            'security_score' => $score,
            'findings'       => $findings,
            'summary'        => $this->buildSummary($findings),
        ];
    }

    private function checkSqlInjection(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);

            // Raw queries with string concatenation or variables
            $dangerousPatterns = [
                '/DB::(?:statement|select|insert|update|delete)\s*\(\s*["\'].*\$/',
                '/whereRaw\s*\(\s*["\'][^"\']*\.\s*\$/',
                '/whereRaw\s*\(\s*["\'][^"\']*\'\s*\.\s*\$/',
                '/->whereRaw\s*\(\s*"\s*[^"]*\$\w+/',
                '/selectRaw\s*\(\s*["\'][^"\']*\.\s*\$/',
                '/DB::statement\s*\(\s*"[^"]*\$/',
                '/DB::select\s*\(\s*"[^"]*\$/',
            ];

            foreach ($dangerousPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $this->addResult(AnalysisResult::make(
                        category:       'sql_injection',
                        severity:       'critical',
                        title:          'Potential SQL Injection vulnerability',
                        description:    "Line " . ($lineNum + 1) . " uses raw SQL with variable interpolation. This is vulnerable to SQL injection.",
                        file:           $filePath,
                        lineStart:      $lineNum + 1,
                        lineEnd:        $lineNum + 1,
                        codeSnippet:    $line,
                        recommendation: 'Use parameterized queries with bindings instead of string concatenation.',
                        codeBefore:     $line,
                        codeAfter:      'DB::select("SELECT * FROM users WHERE id = ?", [$id]);',
                    ));
                    break;
                }
            }
        }
    }

    private function checkHardcodedSecrets(string $filePath, string $content): void
    {
        // Skip env files and config files that legitimately have these
        if (str_ends_with($filePath, '.env') || str_ends_with($filePath, '.env.example')) {
            return;
        }

        $lines = explode("\n", $content);

        $secretPatterns = [
            '/["\']password["\']\s*=>\s*["\'][^"\']{4,}["\']/',
            '/["\']secret["\']\s*=>\s*["\'][^"\']{8,}["\']/',
            '/["\']api_key["\']\s*=>\s*["\'][^"\']{8,}["\']/',
            '/["\']access_token["\']\s*=>\s*["\'][^"\']{8,}["\']/',
            '/\$password\s*=\s*["\'][^"\']{4,}["\']/',
            '/\$secret\s*=\s*["\'][^"\']{8,}["\']/',
            '/sk-[a-zA-Z0-9]{20,}/',           // OpenAI key
            '/AIza[a-zA-Z0-9\-_]{35}/',         // Google API key
            '/AKIA[A-Z0-9]{16}/',               // AWS access key
            '/["\']sk-ant-[a-zA-Z0-9\-_]{20,}/', // Anthropic key
        ];

        foreach ($lines as $lineNum => $line) {
            // Skip comments
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '*')) {
                continue;
            }

            foreach ($secretPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $this->addResult(AnalysisResult::make(
                        category:       'secret_exposure',
                        severity:       'critical',
                        title:          'Hardcoded secret/credential detected',
                        description:    "Line " . ($lineNum + 1) . " appears to contain a hardcoded secret or credential. This is a serious security risk.",
                        file:           $filePath,
                        lineStart:      $lineNum + 1,
                        lineEnd:        $lineNum + 1,
                        codeSnippet:    preg_replace('/(["\'])[^"\']{4,}(["\'])/', '$1***REDACTED***$2', $line),
                        recommendation: 'Move secrets to .env file and access via env() or config(). Never commit secrets to version control.',
                        codeBefore:     '\'api_key\' => \'hardcoded-secret-123\'',
                        codeAfter:      '\'api_key\' => env(\'SERVICE_API_KEY\')',
                    ));
                    break;
                }
            }
        }
    }

    private function checkMissingAuthorization(string $filePath, string $content): void
    {
        if (! $this->isController($filePath)) {
            return;
        }

        // Check if controller has CRUD methods but no authorization
        $hasCrudMethods = preg_match('/public\s+function\s+(store|update|destroy|delete)\s*\(/', $content);

        if (! $hasCrudMethods) {
            return;
        }

        $hasAuthorization = preg_match('/\$this->authorize\s*\(/', $content) ||
                            preg_match('/Gate::/', $content) ||
                            preg_match('/->can\s*\(/', $content) ||
                            preg_match('/middleware\s*\(\s*["\']can:/', $content) ||
                            preg_match('/authorizeResource\s*\(/', $content);

        if (! $hasAuthorization) {
            $this->addResult(AnalysisResult::make(
                category:       'authorization',
                severity:       'high',
                title:          "Missing authorization in {$this->baseName($filePath)}",
                description:    "Controller has store/update/destroy methods but no authorization checks (authorize(), Gate, or Policy). Any authenticated user can perform these actions.",
                file:           $filePath,
                recommendation: 'Add $this->authorize() calls or create a Policy (php artisan make:policy) and use authorizeResource().',
                codeBefore:     "public function update(Request \$request, User \$user) {\n    \$user->update(\$request->all());\n}",
                codeAfter:      "public function update(UpdateUserRequest \$request, User \$user) {\n    \$this->authorize('update', \$user);\n    \$user->update(\$request->validated());\n}",
            ));
        }
    }

    private function checkMassAssignment(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            // Look for create/update with $request->all() or $request->input()
            if (preg_match('/::create\s*\(\s*\$request->all\s*\(\s*\)\s*\)/', $line) ||
                preg_match('/->update\s*\(\s*\$request->all\s*\(\s*\)\s*\)/', $line) ||
                preg_match('/->fill\s*\(\s*\$request->all\s*\(\s*\)\s*\)/', $line)) {

                $this->addResult(AnalysisResult::make(
                    category:       'mass_assignment',
                    severity:       'high',
                    title:          'Mass assignment vulnerability — using $request->all()',
                    description:    "Line " . ($lineNum + 1) . " passes all request data to create/update. An attacker could inject extra fields (e.g., is_admin=true).",
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Use $request->validated() from a FormRequest, or explicitly list fields with $request->only([...]).',
                    codeBefore:     'User::create($request->all());',
                    codeAfter:      'User::create($request->validated()); // or $request->only([\'name\', \'email\'])',
                ));
            }
        }
    }

    private function checkInsecureDirectOutput(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            // Echo with unescaped variable (in blade it's {!! !!})
            if (preg_match('/\{!!\s*\$(?!slot|errors|__)[a-zA-Z_]+/', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'xss',
                    severity:       'high',
                    title:          'Potential XSS — unescaped output {!! !!}',
                    description:    "Line " . ($lineNum + 1) . " uses unescaped Blade output {!! !!}. If this renders user-provided data, it is vulnerable to XSS.",
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Use {{ $variable }} (escaped) unless the HTML is explicitly trusted and sanitized.',
                    codeBefore:     '{!! $user->bio !!}',
                    codeAfter:      '{{ $user->bio }}  {{-- or use Purifier for rich HTML --}}',
                ));
            }
        }
    }

    private function checkMissingCsrfExemption(string $filePath, string $content): void
    {
        // Check webhook controllers that might be missing CSRF exemption
        if (str_contains(strtolower($filePath), 'webhook') &&
            ! str_contains($content, 'VerifyCsrfToken') &&
            ! str_contains($content, 'withoutMiddleware')) {
            // This is just informational
        }
    }

    private function checkDebugCodeLeft(string $filePath, string $content): void
    {
        $debugPatterns = [
            '/\bdd\s*\(/'     => 'dd() debug dump left in code',
            '/\bdump\s*\(/'   => 'dump() debug output left in code',
            '/\bvar_dump\s*\(/' => 'var_dump() left in code',
            '/\bprint_r\s*\(/' => 'print_r() left in code',
            '/\bdie\s*\(/'    => 'die() left in code',
            '/\bexit\s*\(/'   => 'exit() left in code',
        ];

        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                continue;
            }

            foreach ($debugPatterns as $pattern => $message) {
                if (preg_match($pattern, $line)) {
                    $this->addResult(AnalysisResult::make(
                        category:       'debug_code',
                        severity:       'medium',
                        title:          "Debug code left in production: {$message}",
                        description:    "Line " . ($lineNum + 1) . ": {$message}. This should not be in production code.",
                        file:           $filePath,
                        lineStart:      $lineNum + 1,
                        lineEnd:        $lineNum + 1,
                        codeSnippet:    trim($line),
                        recommendation: 'Remove debug statements before committing. Use Log::debug() for logging.',
                    ));
                    break;
                }
            }
        }
    }

    private function checkInsecureFileUpload(string $filePath, string $content): void
    {
        if (! str_contains($content, 'store(') && ! str_contains($content, 'storeAs(')) {
            return;
        }

        // Check for file upload without MIME validation
        $hasUpload          = preg_match('/\$request->file\s*\(/', $content) ||
                              preg_match('/->store\s*\(/', $content);
        $hasValidation      = preg_match('/mimes:|mimetypes:|image|file/', $content);

        if ($hasUpload && ! $hasValidation && $this->isController($filePath)) {
            $this->addResult(AnalysisResult::make(
                category:       'insecure_upload',
                severity:       'high',
                title:          'File upload without MIME type validation',
                description:    "Controller handles file uploads but may be missing MIME type validation in request rules. Attackers could upload malicious files.",
                file:           $filePath,
                recommendation: "Add validation rules: 'file' => 'required|file|mimes:jpg,png,pdf|max:2048'",
                codeBefore:     "'file' => 'required'",
                codeAfter:      "'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240'",
            ));
        }
    }

    private function calculateScore(array $findings): int
    {
        $score   = 100;
        $weights = ['critical' => 25, 'high' => 15, 'medium' => 5, 'low' => 2];

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
