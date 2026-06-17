<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

/**
 * Detects architectural issues:
 *  - Fat controllers / fat models
 *  - Direct DB access in controllers without a service layer
 *  - Inline validation instead of FormRequests
 *  - Overly long methods
 *  - Heavy static facade usage
 */
class ArchitectureAnalyzer extends BaseAnalyzer
{
    private const FAT_CONTROLLER_LINE_THRESHOLD   = 150;
    private const FAT_CONTROLLER_METHOD_THRESHOLD = 8;
    private const FAT_MODEL_LINE_THRESHOLD        = 200;
    private const LONG_METHOD_LINE_THRESHOLD      = 40;
    private const FACADE_USAGE_THRESHOLD          = 5;

    public function getName(): string
    {
        return 'architect';
    }

    public function analyze(array $files): array
    {
        foreach ($files as $filePath => $content) {
            $this->analyzeFile($filePath, $content);
        }

        $findings = $this->flushResults();
        $score    = $this->calculateScore($findings);

        return [
            'agent'              => $this->getName(),
            'architecture_score' => $score,
            'findings'           => $findings,
            'summary'            => $this->buildSummary($findings),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Per-file dispatch
    // ──────────────────────────────────────────────────────────────────────────

    private function analyzeFile(string $filePath, string $content): void
    {
        $lines = $this->lineCount($content);

        if ($this->isController($filePath, $content)) {
            $this->checkFatController($filePath, $content, $lines);
            $this->checkControllerDirectDbAccess($filePath, $content);
            $this->checkMissingFormRequest($filePath, $content);
        }

        if ($this->isModel($filePath, $content) && $lines > self::FAT_MODEL_LINE_THRESHOLD) {
            $this->reportFatModel($filePath, $content, $lines);
        }

        $this->checkLongMethods($filePath, $content);
        $this->checkStaticFacadeOveruse($filePath, $content);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Individual checks
    // ──────────────────────────────────────────────────────────────────────────

    private function checkFatController(string $filePath, string $content, int $lines): void
    {
        if ($lines <= self::FAT_CONTROLLER_LINE_THRESHOLD) {
            return;
        }

        $methodCount = preg_match_all('/public\s+function\s+\w+\s*\(/', $content);
        $severity    = $lines > 300 ? Severity::CRITICAL : ($lines > 200 ? Severity::HIGH : Severity::MEDIUM);
        $className   = $this->className($filePath);
        $serviceName = $this->className($filePath, 'Service');

        $this->addResult(AnalysisResult::make(
            category:       'fat_controller',
            severity:       $severity,
            title:          "Fat Controller: {$className} ({$lines} lines, {$methodCount} methods)",
            description:    "Controller has {$lines} lines and {$methodCount} public methods. Controllers should only handle HTTP request/response; business logic belongs in Service classes.",
            file:           $filePath,
            lineStart:      1,
            lineEnd:        $lines,
            recommendation: "Create {$serviceName} and move business logic there. Inject it via constructor.",
            codeBefore:     "class {$className} extends Controller {\n    public function store(Request \$request) {\n        // 50+ lines of business logic\n    }\n}",
            codeAfter:      "class {$className} extends Controller {\n    public function __construct(private {$serviceName} \$service) {}\n    public function store(StoreRequest \$request) {\n        return \$this->service->create(\$request->validated());\n    }\n}",
        ));
    }

    /**
     * Detect direct Eloquent / DB calls in a controller that has NO service injection.
     *
     * Previously this checked a hardcoded list of 6 model names.
     * Now it detects ANY capitalized class followed by Eloquent static methods,
     * covering all project-specific models.
     */
    private function checkControllerDirectDbAccess(string $filePath, string $content): void
    {
        // Any PascalCase class using common Eloquent static methods
        $eloquentCallPattern = '/\b[A-Z][a-zA-Z]+\s*::\s*(where|find|findOrFail|create|updateOrCreate|firstOrCreate|delete|first|get|all|count|paginate|with)\b/';

        // Raw DB:: usage
        $rawDbPattern = '/\bDB\s*::/';

        // Direct model mutations
        $mutationPattern = '/->(save|delete|update|fill)\s*\(/';

        $hasEloquent  = (bool) preg_match($eloquentCallPattern, $content);
        $hasRawDb     = (bool) preg_match($rawDbPattern, $content);
        $hasMutations = (bool) preg_match($mutationPattern, $content);

        if (! $hasEloquent && ! $hasRawDb && ! $hasMutations) {
            return;
        }

        // If the controller already has service/repository injection it's probably fine
        $hasServiceInjection = (bool) preg_match('/private\s+\w+(Service|Repository)\s+\$/', $content);
        if ($hasServiceInjection) {
            return;
        }

        $this->addResult(AnalysisResult::make(
            category:       'service_layer',
            severity:       Severity::HIGH,
            title:          'Controller directly accesses database without a Service layer',
            description:    "Controller {$this->className($filePath)} queries the database directly. This violates Single Responsibility and makes unit testing impossible without a real database.",
            file:           $filePath,
            recommendation: 'Create a Service class for all business/data logic. Inject it in the controller constructor.',
            codeBefore:     "public function store(Request \$request) {\n    \$user = User::create(\$request->all());\n}",
            codeAfter:      "public function store(StoreUserRequest \$request) {\n    \$user = \$this->userService->create(\$request->validated());\n    return UserResource::make(\$user);\n}",
        ));
    }

    private function checkMissingFormRequest(string $filePath, string $content): void
    {
        $hasDirectValidate = (bool) preg_match('/\$request->validate\s*\(/', $content);
        $hasFormRequest    = (bool) preg_match(
            '/use\s+(App|Modules)\\\\.*Requests\\\\/',
            $content
        );

        if ($hasDirectValidate && ! $hasFormRequest) {
            $this->addResult(AnalysisResult::make(
                category:       'solid',
                severity:       Severity::LOW,
                title:          'Inline validation in controller — use FormRequest',
                description:    "Controller {$this->className($filePath)} uses \$request->validate() inline. Extract to a dedicated FormRequest for reusability and cleaner controllers.",
                file:           $filePath,
                recommendation: 'Run: php artisan make:request Store' . $this->className($filePath, '') . 'Request',
                codeBefore:     "public function store(Request \$request) {\n    \$request->validate(['name' => 'required']);\n}",
                codeAfter:      "public function store(StoreUserRequest \$request) {\n    // Validation is in StoreUserRequest::rules()\n}",
            ));
        }
    }

    private function reportFatModel(string $filePath, string $content, int $lines): void
    {
        $className = $this->className($filePath);

        $this->addResult(AnalysisResult::make(
            category:       'fat_model',
            severity:       Severity::MEDIUM,
            title:          "Fat Model: {$className} ({$lines} lines)",
            description:    "Model has {$lines} lines. Models should contain only relationships, scopes, casts, and accessors. Business logic belongs in a Service.",
            file:           $filePath,
            lineStart:      1,
            lineEnd:        $lines,
            recommendation: "Extract business logic to {$className}Service. Keep the model lean.",
            codeBefore:     "class {$className} extends Model { // {$lines} lines of mixed logic }",
            codeAfter:      "class {$className} extends Model { // Only relationships, casts, scopes }\nclass {$className}Service { // Business logic here }",
        ));
    }

    /**
     * Detect overly long methods.
     * Uses BaseAnalyzer::extractMethods() — no code duplication.
     */
    private function checkLongMethods(string $filePath, string $content): void
    {
        foreach ($this->extractMethods($content) as $method) {
            if ($method['body_lines'] < self::LONG_METHOD_LINE_THRESHOLD) {
                continue;
            }

            $complexity = $this->cyclomaticComplexity($method['body']);
            $severity   = ($complexity > 15 || $method['body_lines'] > 80)
                ? Severity::HIGH
                : Severity::MEDIUM;

            $this->addResult(AnalysisResult::make(
                category:       'solid',
                severity:       $severity,
                title:          "Long method: {$this->className($filePath)}::{$method['name']}() (~{$method['body_lines']} lines, complexity: {$complexity})",
                description:    "Method '{$method['name']}' is ~{$method['body_lines']} lines with cyclomatic complexity {$complexity}. Long methods are hard to test, understand, and maintain.",
                file:           $filePath,
                lineStart:      $method['start_line'],
                lineEnd:        $method['end_line'],
                recommendation: "Break '{$method['name']}' into smaller private methods, each doing one thing. Aim for < 20 lines per method.",
            ));
        }
    }

    private function checkStaticFacadeOveruse(string $filePath, string $content): void
    {
        $count = preg_match_all('/\b(Cache|Log|Mail|Event|Bus|Queue|Storage|Http)\s*::/', $content);

        if ($count < self::FACADE_USAGE_THRESHOLD) {
            return;
        }

        $this->addResult(AnalysisResult::make(
            category:       'dependency_injection',
            severity:       Severity::LOW,
            title:          "Heavy static facade usage in {$this->className($filePath)} ({$count} calls)",
            description:    "File uses {$count} static facade calls. Heavy static usage makes unit testing harder because facades cannot be easily mocked via constructor injection.",
            file:           $filePath,
            recommendation: 'Inject dependencies via constructor (Cache, Mailer, LoggerInterface) for full testability.',
            codeBefore:     "public function send() {\n    Cache::put(...);\n    Mail::send(...);\n}",
            codeAfter:      "public function __construct(\n    private CacheInterface \$cache,\n    private Mailer \$mailer,\n) {}\npublic function send() {\n    \$this->cache->put(...);\n}",
        ));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function className(string $filePath, string $suffix = ''): string
    {
        $name = basename($filePath, '.php');
        return $suffix !== ''
            ? str_replace('Controller', '', $name) . $suffix
            : $name;
    }
}
