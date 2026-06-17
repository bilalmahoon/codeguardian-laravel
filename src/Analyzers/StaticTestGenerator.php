<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Analyzers;

use CodeGuardian\Laravel\Support\FileTypeDetector;

class StaticTestGenerator
{
    /**
     * Generate PHPUnit test file for a given PHP class file.
     */
    public function generateForFile(string $filePath, string $content): ?GeneratedTest
    {
        $className   = $this->extractClassName($content);
        $namespace   = $this->extractNamespace($content);
        $methods     = $this->extractPublicMethods($content);
        $fileType    = FileTypeDetector::classify($filePath, $content);
        $isModel      = $fileType === 'model';
        $isController = $fileType === 'controller';
        $isService    = $fileType === 'service';

        if (! $className || empty($methods)) {
            return null;
        }

        $testClass   = $className . 'Test';
        $testNs      = $this->buildTestNamespace($namespace, $filePath);
        $testPath    = $this->buildTestPath($filePath);

        if ($isModel) {
            $code = $this->generateModelTest($className, $namespace, $testClass, $testNs, $content);
        } elseif ($isController) {
            $code = $this->generateControllerTest($className, $namespace, $testClass, $testNs, $methods);
        } elseif ($isService) {
            $code = $this->generateServiceTest($className, $namespace, $testClass, $testNs, $methods);
        } else {
            $code = $this->generateGenericTest($className, $namespace, $testClass, $testNs, $methods);
        }

        return new GeneratedTest(
            className:    $testClass,
            filePath:     $testPath,
            content:      $code,
            sourceFile:   $filePath,
            methodsCovered: array_column($methods, 'name'),
        );
    }

    private function generateModelTest(
        string $className, string $namespace, string $testClass, string $testNs, string $content
    ): string {
        $fillable   = $this->extractFillable($content);
        $relations  = $this->extractRelationships($content);
        $casts      = $this->extractCasts($content);

        $fillableAssertions = empty($fillable)
            ? "        // No fillable fields detected — add assertions for \$fillable"
            : implode("\n", array_map(
                fn($f) => "        \$this->assertContains('{$f}', \$model->getFillable());",
                $fillable
            ));

        $relationTests = empty($relations) ? '' : implode("\n\n", array_map(function ($rel) use ($className) {
            return "    public function test_{$rel['name']}_relationship_exists(): void\n" .
                   "    {\n" .
                   "        \$model = new {$className}();\n" .
                   "        \$this->assertInstanceOf(\\Illuminate\\Database\\Eloquent\\Relations\\{$rel['type']}::class, \$model->{$rel['name']}());\n" .
                   "    }";
        }, $relations));

        $castTests = empty($casts) ? '' : implode("\n\n", array_map(function ($cast) use ($className) {
            return "    public function test_{$cast['field']}_is_cast_to_{$cast['type']}(): void\n" .
                   "    {\n" .
                   "        \$model = new {$className}();\n" .
                   "        \$this->assertArrayHasKey('{$cast['field']}', \$model->getCasts());\n" .
                   "    }";
        }, $casts));

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$testNs};

use {$namespace}\\{$className};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auto-generated test for {$className} model.
 * Review and complete assertions before running.
 */
class {$testClass} extends TestCase
{
    use RefreshDatabase;

    public function test_model_can_be_instantiated(): void
    {
        \$model = new {$className}();
        \$this->assertInstanceOf({$className}::class, \$model);
    }

    public function test_fillable_fields_are_defined(): void
    {
        \$model = new {$className}();
{$fillableAssertions}
    }

    public function test_model_has_expected_table(): void
    {
        \$model = new {$className}();
        \$this->assertNotEmpty(\$model->getTable());
    }

{$relationTests}

{$castTests}
}
PHP;
    }

    private function generateControllerTest(
        string $className, string $namespace, string $testClass, string $testNs, array $methods
    ): string {
        $routePrefix = strtolower(preg_replace('/Controller$/', '', $className));
        $routePrefix = strtolower(preg_replace('/([A-Z])/', '-$1', lcfirst($routePrefix)));

        $methodTests = implode("\n\n", array_map(function ($method) use ($routePrefix) {
            $httpMethod = $this->guessHttpMethod($method['name']);
            $route      = $this->guessRoute($routePrefix, $method['name']);
            $status     = in_array($method['name'], ['store', 'update']) ? '201' : '200';

            return "    public function test_{$method['name']}_returns_expected_response(): void\n" .
                   "    {\n" .
                   "        \$user = \$this->createAuthenticatedUser();\n" .
                   "\n" .
                   "        \$response = \$this->{$httpMethod}('{$route}');\n" .
                   "\n" .
                   "        // Assert the response status (adjust as needed)\n" .
                   "        \$response->assertStatus({$status});\n" .
                   "    }";
        }, $methods));

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$testNs};

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auto-generated feature test for {$className}.
 * Complete route paths and assertions before running.
 */
class {$testClass} extends TestCase
{
    use RefreshDatabase;

    private function createAuthenticatedUser(): \Illuminate\Contracts\Auth\Authenticatable
    {
        // Replace with your User factory
        \$user = \App\Models\User::factory()->create();
        \$this->actingAs(\$user);
        return \$user;
    }

{$methodTests}
}
PHP;
    }

    private function generateServiceTest(
        string $className, string $namespace, string $testClass, string $testNs, array $methods
    ): string {
        $methodTests = implode("\n\n", array_map(function ($method) use ($className) {
            $params    = $this->buildMockParams($method['params']);
            $paramVars = $this->buildParamVars($method['params']);

            return "    public function test_{$method['name']}_executes_successfully(): void\n" .
                   "    {\n" .
                   $params .
                   "        \$service = \$this->app->make({$className}::class);\n" .
                   "        \$result  = \$service->{$method['name']}({$paramVars});\n" .
                   "\n" .
                   "        // Assert expected outcome\n" .
                   "        \$this->assertNotNull(\$result); // Adjust assertion\n" .
                   "    }";
        }, array_slice($methods, 0, 6))); // limit to 6 methods

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$testNs};

use {$namespace}\\{$className};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auto-generated unit test for {$className}.
 * Fill in proper mock data and assertions.
 */
class {$testClass} extends TestCase
{
    use RefreshDatabase;

    private {$className} \$service;

    protected function setUp(): void
    {
        parent::setUp();
        \$this->service = \$this->app->make({$className}::class);
    }

{$methodTests}
}
PHP;
    }

    private function generateGenericTest(
        string $className, string $namespace, string $testClass, string $testNs, array $methods
    ): string {
        $methodTests = implode("\n\n", array_map(function ($method) use ($className, $namespace) {
            return "    public function test_{$method['name']}_can_be_called(): void\n" .
                   "    {\n" .
                   "        \$instance = \$this->app->make(\\{$namespace}\\{$className}::class);\n" .
                   "\n" .
                   "        // TODO: Provide proper arguments and assert expected result\n" .
                   "        \$this->assertTrue(method_exists(\$instance, '{$method['name']}'));\n" .
                   "    }";
        }, array_slice($methods, 0, 5)));

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$testNs};

use {$namespace}\\{$className};
use Tests\TestCase;

/**
 * Auto-generated test for {$className}.
 */
class {$testClass} extends TestCase
{
    public function test_class_can_be_resolved_from_container(): void
    {
        \$instance = \$this->app->make({$className}::class);
        \$this->assertInstanceOf({$className}::class, \$instance);
    }

{$methodTests}
}
PHP;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Extraction helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function extractClassName(string $content): ?string
    {
        if (preg_match('/(?:class|interface|trait)\s+(\w+)/', $content, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractNamespace(string $content): string
    {
        if (preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $content, $m)) {
            return $m[1];
        }
        return 'App';
    }

    private function extractPublicMethods(string $content): array
    {
        preg_match_all(
            '/public\s+function\s+(\w+)\s*\(([^)]{0,300})\)\s*(?::\s*(\S+))?\s*\{/m',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        $methods = [];
        foreach ($matches as $m) {
            $name = $m[1];
            // Skip magic methods and constructor
            if (str_starts_with($name, '__')) {
                continue;
            }
            $methods[] = [
                'name'       => $name,
                'params'     => $this->parseParams($m[2] ?? ''),
                'returnType' => $m[3] ?? 'mixed',
            ];
        }

        return $methods;
    }

    private function parseParams(string $paramString): array
    {
        if (empty(trim($paramString))) {
            return [];
        }

        $params = [];
        foreach (explode(',', $paramString) as $param) {
            $param = trim($param);
            if (empty($param)) continue;

            preg_match('/(?:(\S+)\s+)?\$(\w+)/', $param, $m);
            $params[] = [
                'type' => $m[1] ?? 'mixed',
                'name' => $m[2] ?? 'param',
            ];
        }

        return $params;
    }

    private function extractFillable(string $content): array
    {
        if (preg_match('/protected\s+\$fillable\s*=\s*\[([^\]]+)\]/s', $content, $m)) {
            preg_match_all('/["\'](\w+)["\']/', $m[1], $fields);
            return $fields[1] ?? [];
        }
        return [];
    }

    private function extractRelationships(string $content): array
    {
        $relations = [];
        $types     = ['hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'hasManyThrough', 'morphMany', 'morphTo'];

        foreach ($types as $type) {
            preg_match_all(
                '/public\s+function\s+(\w+)\s*\(\s*\)[^{]*\{[^}]*\$this->' . $type . '\s*\(/m',
                $content,
                $matches
            );
            foreach ($matches[1] as $name) {
                $relations[] = ['name' => $name, 'type' => ucfirst($type)];
            }
        }

        return $relations;
    }

    private function extractCasts(string $content): array
    {
        $casts = [];
        if (preg_match('/protected\s+\$casts\s*=\s*\[([^\]]+)\]/s', $content, $m)) {
            preg_match_all('/["\'](\w+)["\'\s]*=>\s*["\'](\w+)["\']/', $m[1], $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $casts[] = ['field' => $match[1], 'type' => $match[2]];
            }
        }
        return $casts;
    }

    private function buildTestNamespace(string $sourceNs, string $filePath): string
    {
        // Convert App\Http\Controllers\... to Tests\Feature\... or Tests\Unit\...
        $ns = str_replace('App\\', '', $sourceNs);
        if (str_contains($filePath, 'Controller')) {
            return 'Tests\\Feature\\' . $ns;
        }
        return 'Tests\\Unit\\' . $ns;
    }

    private function buildTestPath(string $filePath): string
    {
        // Convert app/Http/Controllers/UserController.php → tests/Feature/Http/Controllers/UserControllerTest.php
        $relative = str_replace(['app/', 'App/'], '', $filePath);
        $dir      = dirname($relative);
        $file     = basename($relative, '.php') . 'Test.php';

        if (str_contains($filePath, 'Controller')) {
            return "tests/Feature/{$dir}/{$file}";
        }
        return "tests/Unit/{$dir}/{$file}";
    }

    private function guessHttpMethod(string $methodName): string
    {
        return match (true) {
            in_array($methodName, ['store', 'create'])  => 'postJson',
            in_array($methodName, ['update'])            => 'putJson',
            in_array($methodName, ['destroy', 'delete']) => 'deleteJson',
            default                                      => 'getJson',
        };
    }

    private function guessRoute(string $prefix, string $methodName): string
    {
        return match ($methodName) {
            'index'   => "/api/{$prefix}",
            'store'   => "/api/{$prefix}",
            'show'    => "/api/{$prefix}/1",
            'update'  => "/api/{$prefix}/1",
            'destroy' => "/api/{$prefix}/1",
            default   => "/api/{$prefix}",
        };
    }

    private function buildMockParams(array $params): string
    {
        if (empty($params)) {
            return '';
        }

        $lines = [];
        foreach ($params as $p) {
            $type  = strtolower($p['type']);
            $name  = $p['name'];
            $value = match (true) {
                str_contains($type, 'int')    => '1',
                str_contains($type, 'string') => "'test-value'",
                str_contains($type, 'bool')   => 'true',
                str_contains($type, 'array')  => '[]',
                str_contains($type, 'float')  => '1.5',
                default                       => 'null // TODO: provide a proper value',
            };
            $lines[] = "        \${$name} = {$value};";
        }

        return implode("\n", $lines) . "\n";
    }

    private function buildParamVars(array $params): string
    {
        return implode(', ', array_map(fn($p) => '$' . $p['name'], $params));
    }
}
