<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

class ArchitectureAnalyzer extends BaseAnalyzer
{
    private const FAT_CONTROLLER_LINE_THRESHOLD   = 150;
    private const FAT_CONTROLLER_METHOD_THRESHOLD = 8;
    private const FAT_MODEL_LINE_THRESHOLD        = 200;
    private const LONG_METHOD_LINE_THRESHOLD      = 40;

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

    private function analyzeFile(string $filePath, string $content): void
    {
        $lines  = $this->lineCount($content);
        $isCtrl = $this->isController($filePath);
        $isMdl  = $this->isModel($filePath);

        // ── Fat Controller ───────────────────────────────────────────────────
        if ($isCtrl) {
            $this->checkFatController($filePath, $content, $lines);
            $this->checkControllerDirectDbAccess($filePath, $content);
            $this->checkMissingFormRequest($filePath, $content);
        }

        // ── Fat Model ────────────────────────────────────────────────────────
        if ($isMdl && $lines > self::FAT_MODEL_LINE_THRESHOLD) {
            $this->addResult(AnalysisResult::make(
                category:       'fat_model',
                severity:       'medium',
                title:          "Fat Model: {$this->className($filePath)} ({$lines} lines)",
                description:    "Model has {$lines} lines. Models should only contain relationships, scopes, casts, and accessors. Business logic should be in Services.",
                file:           $filePath,
                lineStart:      1,
                lineEnd:        $lines,
                recommendation: 'Extract business logic to a dedicated Service class. Keep model lean.',
                codeBefore:     "class {$this->className($filePath)} extends Model { // {$lines} lines of mixed logic }",
                codeAfter:      "class {$this->className($filePath)} extends Model { // Only relationships, casts, scopes }\nclass {$this->className($filePath)}Service { // Business logic here }",
            ));
        }

        // ── Long methods (all files) ─────────────────────────────────────────
        $this->checkLongMethods($filePath, $content);

        // ── Missing dependency injection ─────────────────────────────────────
        $this->checkStaticFacadeOveruse($filePath, $content);
    }

    private function checkFatController(string $filePath, string $content, int $lines): void
    {
        if ($lines <= self::FAT_CONTROLLER_LINE_THRESHOLD) {
            return;
        }

        // Count public methods
        $methodCount = preg_match_all('/public\s+function\s+\w+\s*\(/', $content);

        $severity = $lines > 300 ? 'critical' : ($lines > 200 ? 'high' : 'medium');

        $this->addResult(AnalysisResult::make(
            category:       'fat_controller',
            severity:       $severity,
            title:          "Fat Controller: {$this->className($filePath)} ({$lines} lines, {$methodCount} methods)",
            description:    "Controller has {$lines} lines and {$methodCount} public methods. Controllers should only handle HTTP request/response. Business logic belongs in Service classes.",
            file:           $filePath,
            lineStart:      1,
            lineEnd:        $lines,
            recommendation: 'Create a ' . $this->className($filePath, 'Service') . ' and move business logic there. Inject it via constructor.',
            codeBefore:     "class {$this->className($filePath)} extends Controller {\n    public function store(Request \$request) {\n        // 50+ lines of business logic\n    }\n}",
            codeAfter:      "class {$this->className($filePath)} extends Controller {\n    public function __construct(private {$this->className($filePath, 'Service')} \$service) {}\n    public function store(StoreRequest \$request) {\n        return \$this->service->create(\$request->validated());\n    }\n}",
        ));
    }

    private function checkControllerDirectDbAccess(string $filePath, string $content): void
    {
        // Look for Eloquent queries directly in controller (not via service/repository)
        $dbPatterns = [
            '/\b(User|Order|Product|Invoice|Payment|Customer)\s*::\s*(where|find|create|update|delete|first|get|all)\b/',
            '/->save\(\)/',
            '/->delete\(\)/',
            '/DB::/',
        ];

        $found = false;
        foreach ($dbPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $found = true;
                break;
            }
        }

        if (! $found) {
            return;
        }

        // Check if constructor has service injection — if yes, less severe
        $hasServiceInjection = preg_match('/private\s+\w+Service\s+\$/', $content) ||
                               preg_match('/private\s+\w+Repository\s+\$/', $content);

        if ($hasServiceInjection) {
            return; // Has service layer, probably OK
        }

        $this->addResult(AnalysisResult::make(
            category:       'service_layer',
            severity:       'high',
            title:          'Controller directly accesses database without Service layer',
            description:    "Controller {$this->className($filePath)} queries the database directly. This violates Single Responsibility and makes testing hard.",
            file:           $filePath,
            recommendation: 'Create a Service class for business logic. Inject it in the controller constructor.',
            codeBefore:     "public function store(Request \$request) {\n    \$user = User::create(\$request->all());\n    // more direct DB calls...\n}",
            codeAfter:      "public function store(StoreUserRequest \$request) {\n    \$user = \$this->userService->create(\$request->validated());\n    return UserResource::make(\$user);\n}",
        ));
    }

    private function checkMissingFormRequest(string $filePath, string $content): void
    {
        // Check if controller uses Request directly instead of FormRequest for validation
        $hasDirectValidate = preg_match('/\$request->validate\s*\(/', $content);
        $hasFormRequest    = preg_match('/use\s+App\\\\Http\\\\Requests\\\\/', $content) ||
                             preg_match('/use\s+Modules\\\\.*\\\\Requests\\\\/', $content);

        if ($hasDirectValidate && ! $hasFormRequest) {
            $this->addResult(AnalysisResult::make(
                category:       'solid',
                severity:       'low',
                title:          'Inline validation in controller — use FormRequest',
                description:    "Controller {$this->className($filePath)} uses \$request->validate() inline. Extract to a dedicated FormRequest class for reusability and cleaner controllers.",
                file:           $filePath,
                recommendation: 'Create a FormRequest class (php artisan make:request) and move validation rules there.',
                codeBefore:     "public function store(Request \$request) {\n    \$request->validate(['name' => 'required', ...]);\n}",
                codeAfter:      "public function store(StoreUserRequest \$request) {\n    // Validation is in StoreUserRequest::rules()\n}",
            ));
        }
    }

    private function checkLongMethods(string $filePath, string $content): void
    {
        // Find methods and measure their length
        preg_match_all(
            '/(?:public|private|protected)\s+function\s+(\w+)\s*\([^)]*\)\s*(?::\s*\S+)?\s*\{/m',
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

            // Walk forward from method start to find closing brace at depth 0
            $depth     = 0;
            $endLine   = $methodLine;
            $started   = false;
            for ($ln = $methodLine - 1; $ln < $totalLines && $ln < $methodLine + 300; $ln++) {
                $l = $contentLines[$ln];
                $depth += substr_count($l, '{') - substr_count($l, '}');
                if (! $started && $depth > 0) {
                    $started = true;
                }
                if ($started && $depth <= 0) {
                    $endLine = $ln + 1;
                    break;
                }
            }

            $bodyLines  = max(0, $endLine - $methodLine);
            $methodBody = implode("\n", array_slice($contentLines, $methodLine - 1, $bodyLines + 1));

            if ($bodyLines < self::LONG_METHOD_LINE_THRESHOLD) {
                continue;
            }

            $complexity = $this->cyclomaticComplexity($methodBody);
            $severity   = $complexity > 15 ? 'high' : ($bodyLines > 80 ? 'high' : 'medium');

            $this->addResult(AnalysisResult::make(
                category:       'solid',
                severity:       $severity,
                title:          "Long method: {$this->className($filePath)}::{$methodName}() (~{$bodyLines} lines, complexity: {$complexity})",
                description:    "Method '{$methodName}' is ~{$bodyLines} lines with cyclomatic complexity of {$complexity}. Long methods are hard to test, understand, and maintain.",
                file:           $filePath,
                lineStart:      $methodLine,
                lineEnd:        $methodLine + $bodyLines,
                recommendation: "Break '{$methodName}' into smaller private methods, each doing one thing. Aim for methods under 20 lines.",
            ));
        }
    }

    private function checkStaticFacadeOveruse(string $filePath, string $content): void
    {
        // Count static facade calls like Cache::, Log::, Mail::, Event::
        $facadePattern = '/\b(Cache|Log|Mail|Event|Bus|Queue|Storage|Http)\s*::/';
        $count         = preg_match_all($facadePattern, $content);

        if ($count < 5) {
            return;
        }

        $this->addResult(AnalysisResult::make(
            category:       'dependency_injection',
            severity:       'low',
            title:          "Heavy static facade usage in {$this->className($filePath)} ({$count} calls)",
            description:    "File uses {$count} static facade calls. While facades are convenient, heavy use makes testing harder. Prefer constructor injection.",
            file:           $filePath,
            recommendation: 'Inject dependencies via constructor instead of using static facades for testability.',
            codeBefore:     "public function send() {\n    Cache::put(...);\n    Mail::send(...);\n    Log::info(...);\n}",
            codeAfter:      "public function __construct(\n    private Cache \$cache,\n    private Mailer \$mailer,\n    private LoggerInterface \$logger\n) {}\npublic function send() {\n    \$this->cache->put(...);\n}",
        ));
    }

    private function calculateScore(array $findings): int
    {
        $score    = 100;
        $weights  = ['critical' => 20, 'high' => 10, 'medium' => 5, 'low' => 2];

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

    private function className(string $filePath, string $suffix = ''): string
    {
        $name = basename($filePath, '.php');
        return $suffix ? str_replace('Controller', '', $name) . $suffix : $name;
    }
}
