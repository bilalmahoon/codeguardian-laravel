<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

class SecurityAnalyzer extends BaseAnalyzer
{
    public function getName(): string
    {
        return 'security';
    }

    public function analyze(array $files, ?callable $onFile = null): array
    {
        foreach ($files as $filePath => $content) {
            $this->tick($onFile, $filePath);
            $this->checkSqlInjection($filePath, $content);
            $this->checkHardcodedSecrets($filePath, $content);
            $this->checkMissingAuthorization($filePath, $content);
            $this->checkMassAssignment($filePath, $content);
            $this->checkInsecureDirectOutput($filePath, $content);
            $this->checkMissingCsrfExemption($filePath, $content);
            $this->checkDebugCodeLeft($filePath, $content);
            $this->checkInsecureFileUpload($filePath, $content);
            // ── Extended checks (OWASP Top 10 / CWE mapped) ──────────────────
            $this->checkCommandInjection($filePath, $content);
            $this->checkCodeInjection($filePath, $content);
            $this->checkInsecureDeserialization($filePath, $content);
            $this->checkWeakCryptography($filePath, $content);
            $this->checkInsecureRandomness($filePath, $content);
            $this->checkPathTraversal($filePath, $content);
            $this->checkServerSideRequestForgery($filePath, $content);
            $this->checkOpenRedirect($filePath, $content);
            $this->checkUnguardedMassAssignment($filePath, $content);
            $this->checkDisabledCsrf($filePath, $content);
            $this->checkDebugModeEnabled($filePath, $content);
            $this->checkDynamicInclude($filePath, $content);
            $this->checkDisabledSslVerification($filePath, $content);
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
                        confidence:     'high',
                        impact:         'Full database read/write/exfiltration; potential RCE via stacked queries.',
                        effort:         'small',
                        breakingRisk:   'low',
                        rootCause:      'User-controlled input concatenated directly into a SQL string.',
                        cwe:            'CWE-89',
                        owasp:          'A03:2021-Injection',
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
                        confidence:     'high',
                        impact:         'Leaked credential allows account/service takeover if the repo is exposed.',
                        effort:         'small',
                        breakingRisk:   'low',
                        rootCause:      'Secret committed to source instead of being injected from the environment.',
                        cwe:            'CWE-798',
                        owasp:          'A07:2021-Identification and Authentication Failures',
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
                confidence:     'medium',
                impact:         'Any authenticated user can create/modify/delete records they do not own (IDOR / broken access control).',
                effort:         'medium',
                breakingRisk:   'medium',
                rootCause:      'State-changing actions exposed without an authorization gate or policy.',
                cwe:            'CWE-862',
                owasp:          'A01:2021-Broken Access Control',
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
                    confidence:     'high',
                    impact:         'Privilege escalation — attacker sets unexpected columns (e.g. is_admin, role_id).',
                    effort:         'small',
                    breakingRisk:   'low',
                    rootCause:      'Entire request payload passed unfiltered to a persistence call.',
                    cwe:            'CWE-915',
                    owasp:          'A08:2021-Software and Data Integrity Failures',
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
                    confidence:     'medium',
                    impact:         'Stored/reflected XSS — attacker runs JavaScript in victims\' browsers, stealing sessions.',
                    effort:         'trivial',
                    breakingRisk:   'low',
                    rootCause:      'Unescaped Blade output rendering potentially user-controlled data.',
                    cwe:            'CWE-79',
                    owasp:          'A03:2021-Injection',
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
        // Skip test files — dd() / dump() are expected there
        if (str_contains($filePath, 'test') || str_contains($filePath, 'Test') ||
            str_contains($filePath, 'spec') || str_contains($filePath, 'Spec')) {
            return;
        }

        $debugPatterns = [
            '/\bdd\s*\(/'       => 'dd() debug dump left in code',
            '/\bdump\s*\(/'     => 'dump() debug output left in code',
            '/\bvar_dump\s*\(/' => 'var_dump() left in code',
            '/\bprint_r\s*\(/'  => 'print_r() left in code',
        ];

        $lines   = explode("\n", $content);
        $found   = 0;
        $maxPerFile = 3; // Cap to avoid flooding

        foreach ($lines as $lineNum => $line) {
            if ($found >= $maxPerFile) break;

            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '*')) {
                continue;
            }

            foreach ($debugPatterns as $pattern => $message) {
                if (preg_match($pattern, $line)) {
                    $this->addResult(AnalysisResult::make(
                        category:       'debug_code',
                        severity:       'medium',
                        title:          "Debug code left in production: {$message}",
                        description:    "Line " . ($lineNum + 1) . ": {$message}. Should not be in production code.",
                        file:           $filePath,
                        lineStart:      $lineNum + 1,
                        lineEnd:        $lineNum + 1,
                        codeSnippet:    trim($line),
                        recommendation: 'Remove debug statements before committing. Use Log::debug() for logging.',
                        confidence:     'high',
                        impact:         'Halts execution and can leak internal state/PII to end users.',
                        effort:         'trivial',
                        breakingRisk:   'low',
                        rootCause:      'Debug helper left in code after troubleshooting.',
                        cwe:            'CWE-489',
                        owasp:          'A05:2021-Security Misconfiguration',
                    ));
                    $found++;
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
                confidence:     'medium',
                impact:         'Attacker uploads a web shell / malicious file leading to RCE or stored XSS.',
                effort:         'small',
                breakingRisk:   'low',
                rootCause:      'Uploaded file accepted without MIME/extension/size constraints.',
                cwe:            'CWE-434',
                owasp:          'A04:2021-Insecure Design',
            ));
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Extended checks — OWASP Top 10 / CWE mapped
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Does this line read user-controllable input?
     * Used to raise confidence on injection-style findings (taint signal).
     */
    private function looksTainted(string $line): bool
    {
        return (bool) preg_match(
            '/(\$request->|request\s*\(\s*\)|->input\s*\(|->query\s*\(|->post\s*\(|->get\s*\(|Input::|\$_GET|\$_POST|\$_REQUEST|\$_COOKIE|\$_SERVER)/',
            $line
        );
    }

    private function isCommentLine(string $line): bool
    {
        $t = ltrim($line);
        return str_starts_with($t, '//') || str_starts_with($t, '#') || str_starts_with($t, '*');
    }

    private function checkCommandInjection(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if ($this->isCommentLine($line)) {
                continue;
            }
            // OS command sink containing a PHP variable argument.
            if (preg_match('/\b(exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(\s*[^)]*\$/', $line)) {
                $tainted = $this->looksTainted($line);
                $this->addResult(AnalysisResult::make(
                    category:       'command_injection',
                    severity:       'critical',
                    title:          'Possible OS command injection',
                    description:    'Line ' . ($lineNum + 1) . ': a shell-execution function is called with a variable argument. If any part is user-controlled this allows arbitrary command execution.',
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Avoid the shell. Use escapeshellarg() on every argument, or a native PHP API. Prefer Symfony Process with an array of arguments.',
                    codeBefore:     'exec("convert " . $request->file);',
                    codeAfter:      'Process::fromShellCommandline(...)->run(); // or escapeshellarg($arg)',
                    confidence:     $tainted ? 'high' : 'medium',
                    impact:         'Remote code execution on the server.',
                    effort:         'medium',
                    breakingRisk:   'medium',
                    rootCause:      'Untrusted data reaches an OS command sink.',
                    cwe:            'CWE-78',
                    owasp:          'A03:2021-Injection',
                ));
            }
        }
    }

    private function checkCodeInjection(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if ($this->isCommentLine($line)) {
                continue;
            }
            if (preg_match('/\beval\s*\(/', $line) || preg_match('/\bcreate_function\s*\(/', $line) ||
                preg_match('/\bassert\s*\(\s*["\']/', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'code_injection',
                    severity:       'critical',
                    title:          'Dynamic PHP code evaluation (eval/create_function)',
                    description:    'Line ' . ($lineNum + 1) . ': PHP code is evaluated at runtime. This is extremely dangerous and almost never necessary.',
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Remove eval()/create_function(). Use a whitelist/match expression or proper callables instead.',
                    confidence:     $this->looksTainted($line) ? 'high' : 'medium',
                    impact:         'Arbitrary code execution if any evaluated input is attacker-influenced.',
                    effort:         'medium',
                    breakingRisk:   'medium',
                    rootCause:      'Runtime evaluation of a code string.',
                    cwe:            'CWE-95',
                    owasp:          'A03:2021-Injection',
                ));
            }
        }
    }

    private function checkInsecureDeserialization(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if ($this->isCommentLine($line)) {
                continue;
            }
            if (preg_match('/\bunserialize\s*\(\s*[^)]*\$/', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'insecure_deserialization',
                    severity:       $this->looksTainted($line) ? 'high' : 'medium',
                    title:          'Insecure deserialization with unserialize()',
                    description:    'Line ' . ($lineNum + 1) . ': unserialize() is called on a variable. Untrusted serialized data can trigger object injection / property-oriented programming gadget chains.',
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Use json_decode() for data interchange. If unserialize() is required, pass ["allowed_classes" => false].',
                    codeBefore:     '$data = unserialize($request->input("payload"));',
                    codeAfter:      '$data = json_decode($request->input("payload"), true);',
                    confidence:     'medium',
                    impact:         'Object injection leading to RCE, file write, or auth bypass depending on available gadgets.',
                    effort:         'small',
                    breakingRisk:   'medium',
                    rootCause:      'Deserializing attacker-controlled data into PHP objects.',
                    cwe:            'CWE-502',
                    owasp:          'A08:2021-Software and Data Integrity Failures',
                ));
            }
        }
    }

    private function checkWeakCryptography(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if ($this->isCommentLine($line)) {
                continue;
            }
            $usesWeakHash = preg_match('/\b(md5|sha1)\s*\(/', $line)
                || preg_match('/hash\s*\(\s*["\'](md5|sha1)["\']/', $line);
            $aboutSecret  = preg_match('/password|passwd|secret|token|api[_-]?key|credential/i', $line);

            if ($usesWeakHash && $aboutSecret) {
                $this->addResult(AnalysisResult::make(
                    category:       'weak_cryptography',
                    severity:       'high',
                    title:          'Weak hashing algorithm used for a secret',
                    description:    'Line ' . ($lineNum + 1) . ': md5()/sha1() is used in a security-sensitive context. These are fast and broken for password/token hashing.',
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Use Hash::make()/password_hash() (bcrypt/argon2) for passwords and hash_hmac("sha256", ...) for tokens.',
                    codeBefore:     '$hash = md5($password);',
                    codeAfter:      '$hash = Hash::make($password); // bcrypt/argon2id',
                    confidence:     'high',
                    impact:         'Hashes are trivially cracked/precomputed, exposing credentials.',
                    effort:         'small',
                    breakingRisk:   'medium',
                    rootCause:      'Fast, collision-prone hash used where a slow KDF or HMAC is required.',
                    cwe:            'CWE-327',
                    owasp:          'A02:2021-Cryptographic Failures',
                ));
            }
        }
    }

    private function checkInsecureRandomness(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if ($this->isCommentLine($line)) {
                continue;
            }
            $usesWeakRandom = preg_match('/\b(rand|mt_rand|uniqid|lcg_value|shuffle|array_rand)\s*\(/', $line);
            $aboutSecret    = preg_match('/token|otp|password|secret|api[_-]?key|nonce|salt|reset|verif|csrf/i', $line);

            if ($usesWeakRandom && $aboutSecret) {
                $this->addResult(AnalysisResult::make(
                    category:       'insecure_randomness',
                    severity:       'high',
                    title:          'Predictable randomness for a security value',
                    description:    'Line ' . ($lineNum + 1) . ': a non-cryptographic RNG is used to generate a security-sensitive value. These are predictable and can be brute-forced.',
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Use random_bytes()/random_int() or Str::random()/Illuminate\\Support\\Str::password() for tokens.',
                    codeBefore:     '$token = md5(uniqid(mt_rand(), true));',
                    codeAfter:      '$token = bin2hex(random_bytes(32)); // or Str::random(64)',
                    confidence:     'medium',
                    impact:         'Predictable tokens enable account takeover (password resets, email verification, OTP).',
                    effort:         'small',
                    breakingRisk:   'low',
                    rootCause:      'Statistical RNG used where a CSPRNG is required.',
                    cwe:            'CWE-338',
                    owasp:          'A02:2021-Cryptographic Failures',
                ));
            }
        }
    }

    private function checkPathTraversal(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if ($this->isCommentLine($line) || ! $this->looksTainted($line)) {
                continue;
            }
            if (preg_match('/\b(file_get_contents|fopen|readfile|file_put_contents|unlink|fwrite|fread)\s*\(/', $line)
                || preg_match('/(Storage|File)::\s*(get|put|delete|exists|download|append|prepend)\s*\(/', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'path_traversal',
                    severity:       'high',
                    title:          'Possible path traversal in file operation',
                    description:    'Line ' . ($lineNum + 1) . ': a filesystem operation uses request-controlled input as (part of) the path. Attackers can use ../ sequences to read/write arbitrary files.',
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Validate against an allow-list, use basename(), and resolve with realpath() ensuring the result stays inside the intended directory.',
                    codeBefore:     'return response()->file(storage_path($request->input("path")));',
                    codeAfter:      '$name = basename($request->input("path"));\nabort_unless(in_array($name, $allowed), 404);',
                    confidence:     'medium',
                    impact:         'Arbitrary file read/write/deletion (config, .env, source).',
                    effort:         'medium',
                    breakingRisk:   'low',
                    rootCause:      'Unvalidated user input used to build a filesystem path.',
                    cwe:            'CWE-22',
                    owasp:          'A01:2021-Broken Access Control',
                ));
            }
        }
    }

    private function checkServerSideRequestForgery(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if ($this->isCommentLine($line) || ! $this->looksTainted($line)) {
                continue;
            }
            if (preg_match('/Http::\s*(get|post|put|patch|delete|head|send|withToken|acceptJson)\s*\(/', $line)
                || preg_match('/\b(curl_init|curl_setopt|file_get_contents|fopen)\s*\(/', $line)
                || preg_match('/->\s*(request|get|post)\s*\(\s*["\']?https?/i', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'ssrf',
                    severity:       'high',
                    title:          'Possible Server-Side Request Forgery (SSRF)',
                    description:    'Line ' . ($lineNum + 1) . ': an outbound HTTP/URL request is built from request-controlled input. Attackers can target internal services (169.254.169.254, localhost, internal APIs).',
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Allow-list permitted hosts/schemes, resolve & block private IP ranges, and disallow redirects to internal addresses.',
                    confidence:     'medium',
                    impact:         'Access to cloud metadata, internal services, and SSRF-to-RCE pivots.',
                    effort:         'medium',
                    breakingRisk:   'low',
                    rootCause:      'User-controlled URL passed to an HTTP client without host validation.',
                    cwe:            'CWE-918',
                    owasp:          'A10:2021-Server-Side Request Forgery',
                ));
            }
        }
    }

    private function checkOpenRedirect(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if ($this->isCommentLine($line)) {
                continue;
            }
            if (preg_match('/redirect\s*\(\s*\$request->/', $line)
                || preg_match('/->\s*(to|away)\s*\(\s*\$request->/', $line)
                || preg_match('/redirect\s*\(\s*[^)]*->(input|query|get)\s*\(/', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'open_redirect',
                    severity:       'medium',
                    title:          'Possible open redirect',
                    description:    'Line ' . ($lineNum + 1) . ': a redirect target is taken from request input. Attackers can craft links to your domain that redirect victims to phishing sites.',
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Redirect only to allow-listed paths/route names, or validate the host against your app URL.',
                    codeBefore:     'return redirect($request->input("next"));',
                    codeAfter:      'return redirect()->to(url($request->input("next")) , 302, [], false) ;// validate host first',
                    confidence:     'medium',
                    impact:         'Phishing and OAuth token theft via trusted-domain redirects.',
                    effort:         'small',
                    breakingRisk:   'low',
                    rootCause:      'Unvalidated user input used as a redirect destination.',
                    cwe:            'CWE-601',
                    owasp:          'A01:2021-Broken Access Control',
                ));
            }
        }
    }

    private function checkUnguardedMassAssignment(string $filePath, string $content): void
    {
        if (! $this->isModel($filePath, $content)) {
            return;
        }
        if (preg_match('/protected\s+\$guarded\s*=\s*\[\s*\]\s*;/', $content)) {
            $lineNum = 0;
            foreach (explode("\n", $content) as $i => $line) {
                if (preg_match('/protected\s+\$guarded\s*=\s*\[\s*\]/', $line)) {
                    $lineNum = $i + 1;
                    break;
                }
            }
            $this->addResult(AnalysisResult::make(
                category:       'mass_assignment',
                severity:       'medium',
                title:          'Model is fully mass-assignable ($guarded = [])',
                description:    "Model {$this->baseName($filePath)} disables mass-assignment protection entirely. Combined with create()/update() on request data this allows attackers to set any column.",
                file:           $filePath,
                lineStart:      $lineNum,
                lineEnd:        $lineNum,
                codeSnippet:    'protected $guarded = [];',
                recommendation: 'Define an explicit $fillable allow-list instead of an empty $guarded.',
                codeBefore:     'protected $guarded = [];',
                codeAfter:      "protected \$fillable = ['name', 'email'];",
                confidence:     'high',
                impact:         'Privilege escalation by setting unexpected columns (is_admin, role_id, balance).',
                effort:         'small',
                breakingRisk:   'medium',
                rootCause:      'Mass-assignment guard disabled at the model level.',
                cwe:            'CWE-915',
                owasp:          'A08:2021-Software and Data Integrity Failures',
            ));
        }
    }

    private function checkDisabledCsrf(string $filePath, string $content): void
    {
        if (preg_match('/protected\s+\$except\s*=\s*\[[^\]]*[\'"]\*[\'"]/s', $content)) {
            $this->addResult(AnalysisResult::make(
                category:       'csrf',
                severity:       'high',
                title:          'CSRF protection disabled for all routes ($except = [\'*\'])',
                description:    'The CSRF middleware excludes every route with a wildcard. This disables CSRF protection application-wide.',
                file:           $filePath,
                recommendation: 'Only exempt specific stateless endpoints (webhooks). Never use a "*" wildcard.',
                codeBefore:     "protected \$except = ['*'];",
                codeAfter:      "protected \$except = ['stripe/webhook'];",
                confidence:     'high',
                impact:         'Cross-site request forgery against any state-changing endpoint.',
                effort:         'small',
                breakingRisk:   'low',
                rootCause:      'Blanket CSRF exemption.',
                cwe:            'CWE-352',
                owasp:          'A01:2021-Broken Access Control',
            ));
        }
    }

    private function checkDebugModeEnabled(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if ($this->isCommentLine($line)) {
                continue;
            }
            if (preg_match('/[\'"]debug[\'"]\s*=>\s*true\b/', $line)
                || preg_match('/config\s*\(\s*\[\s*[\'"]app\.debug[\'"]\s*=>\s*true/', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'security_misconfiguration',
                    severity:       'medium',
                    title:          'Debug mode hard-enabled',
                    description:    'Line ' . ($lineNum + 1) . ": debug is set to a literal true rather than env('APP_DEBUG', false). In production this leaks stack traces, queries, and environment data.",
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: "Use 'debug' => (bool) env('APP_DEBUG', false) and keep APP_DEBUG=false in production.",
                    confidence:     'high',
                    impact:         'Sensitive information disclosure via verbose error pages.',
                    effort:         'trivial',
                    breakingRisk:   'low',
                    rootCause:      'Debug flag not bound to the environment.',
                    cwe:            'CWE-489',
                    owasp:          'A05:2021-Security Misconfiguration',
                ));
            }
        }
    }

    private function checkDynamicInclude(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if ($this->isCommentLine($line) || ! $this->looksTainted($line)) {
                continue;
            }
            if (preg_match('/\b(include|include_once|require|require_once)\b\s*\(?\s*[^;]*\$/', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'file_inclusion',
                    severity:       'high',
                    title:          'Dynamic file inclusion from request input',
                    description:    'Line ' . ($lineNum + 1) . ': an include/require path is built from request input. This enables Local/Remote File Inclusion.',
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Map user input to a fixed allow-list of files; never concatenate request data into an include path.',
                    confidence:     'medium',
                    impact:         'Local/Remote File Inclusion leading to RCE.',
                    effort:         'medium',
                    breakingRisk:   'low',
                    rootCause:      'User input used in a file-inclusion path.',
                    cwe:            'CWE-98',
                    owasp:          'A03:2021-Injection',
                ));
            }
        }
    }

    private function checkDisabledSslVerification(string $filePath, string $content): void
    {
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if ($this->isCommentLine($line)) {
                continue;
            }
            if (preg_match('/[\'"]verify[\'"]\s*=>\s*false/', $line)
                || preg_match('/CURLOPT_SSL_VERIFY(?:PEER|HOST)\s*,\s*(?:false|0)\b/', $line)
                || preg_match('/\bwithoutVerifying\s*\(/', $line)) {
                $this->addResult(AnalysisResult::make(
                    category:       'tls_verification',
                    severity:       'high',
                    title:          'TLS certificate verification disabled',
                    description:    'Line ' . ($lineNum + 1) . ': SSL/TLS peer verification is turned off for an outbound request. This exposes traffic to man-in-the-middle attacks.',
                    file:           $filePath,
                    lineStart:      $lineNum + 1,
                    lineEnd:        $lineNum + 1,
                    codeSnippet:    trim($line),
                    recommendation: 'Keep certificate verification enabled. Fix the underlying CA-bundle issue instead of disabling verification.',
                    codeBefore:     "Http::withoutVerifying()->get(\$url);",
                    codeAfter:      "Http::get(\$url); // ensure the CA bundle is configured",
                    confidence:     'high',
                    impact:         'Man-in-the-middle interception of credentials and data in transit.',
                    effort:         'small',
                    breakingRisk:   'medium',
                    rootCause:      'Certificate validation disabled to work around TLS errors.',
                    cwe:            'CWE-295',
                    owasp:          'A07:2021-Identification and Authentication Failures',
                ));
            }
        }
    }

    protected function calculateScore(array $findings): int
    {
        $score   = 100;
        $weights = ['critical' => 25, 'high' => 15, 'medium' => 5, 'low' => 2];

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

    private function baseName(string $filePath): string
    {
        return basename($filePath, '.php');
    }
}
