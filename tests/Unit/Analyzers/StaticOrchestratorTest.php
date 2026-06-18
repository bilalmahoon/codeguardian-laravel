<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Analyzers;

use CodeGuardian\Laravel\Analyzers\RefactorResult;
use CodeGuardian\Laravel\Analyzers\StaticOrchestrator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CodeGuardian\Laravel\Analyzers\StaticOrchestrator
 */
class StaticOrchestratorTest extends TestCase
{
    private StaticOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->orchestrator = new StaticOrchestrator();
    }

    // ── analyze() ────────────────────────────────────────────────────────────

    public function test_analyze_returns_expected_keys(): void
    {
        $result = $this->orchestrator->analyze(['app/Models/User.php' => '<?php class User {}']);

        $this->assertArrayHasKey('files_scanned',  $result);
        $this->assertArrayHasKey('total_lines',    $result);
        $this->assertArrayHasKey('overall_score',  $result);
        $this->assertArrayHasKey('grade',          $result);
        $this->assertArrayHasKey('agents',         $result);
        $this->assertArrayHasKey('all_findings',   $result);
        $this->assertArrayHasKey('summary',        $result);
    }

    public function test_analyze_counts_files_correctly(): void
    {
        $files = [
            'app/A.php' => '<?php class A {}',
            'app/B.php' => '<?php class B {}',
            'app/C.php' => '<?php class C {}',
        ];

        $result = $this->orchestrator->analyze($files);

        $this->assertSame(3, $result['files_scanned']);
    }

    public function test_analyze_disables_individual_analyzers(): void
    {
        $files  = ['app/Models/User.php' => '<?php class User {}'];
        $result = $this->orchestrator->analyze($files, [
            'architecture' => false,
            'security'     => false,
            'performance'  => false,
            'tech_debt'    => true,
        ]);

        $agentNames = array_column($result['agents'], 'agent');
        $this->assertNotContains('architect',   $agentNames);
        $this->assertNotContains('security',    $agentNames);
        $this->assertNotContains('performance', $agentNames);
        $this->assertContains('tech_debt',      $agentNames);
    }

    public function test_overall_score_between_0_and_100(): void
    {
        $result = $this->orchestrator->analyze([
            'app/Http/Controllers/BigController.php' => $this->makeDirtyController(),
        ]);

        $this->assertGreaterThanOrEqual(0,   $result['overall_score']);
        $this->assertLessThanOrEqual(100,    $result['overall_score']);
    }

    public function test_grade_is_one_of_valid_values(): void
    {
        $result = $this->orchestrator->analyze(['app/Models/User.php' => '<?php class User {}']);
        $this->assertContains($result['grade'], ['A', 'B', 'C', 'D', 'F']);
    }

    // ── refactorFile() ────────────────────────────────────────────────────────

    public function test_refactorFile_replaces_request_all_with_validated(): void
    {
        $content = <<<'PHP'
<?php
class UserController extends Controller
{
    public function store(Request $request)
    {
        User::create($request->all());
    }
}
PHP;

        $findings = [['category' => 'mass_assignment', 'file' => 'app/Http/Controllers/UserController.php', 'line_start' => 5]];
        $result   = $this->orchestrator->refactorFile('app/Http/Controllers/UserController.php', $content, $findings);

        $this->assertInstanceOf(RefactorResult::class, $result);
        $this->assertTrue($result->hasChanges());
        $this->assertStringContainsString('$request->validated(', $result->refactored);
        $this->assertStringNotContainsString('$request->all()', $result->refactored);
    }

    public function test_refactorFile_removes_dd_statements(): void
    {
        $content  = "<?php\nclass Foo {\n    public function bar() {\n        dd(\$data);\n        return 1;\n    }\n}";
        $findings = [['category' => 'debug_code', 'file' => 'app/Foo.php', 'line_start' => 4]];
        $result   = $this->orchestrator->refactorFile('app/Foo.php', $content, $findings);

        $this->assertStringNotContainsString('dd(', $result->refactored);
        $this->assertGreaterThan(0, $result->autoFixed);
    }

    public function test_refactorFile_replaces_select_all_with_paginate_when_safe(): void
    {
        $content  = "<?php\nclass DashboardController extends Controller {\n    public function index() {\n        \$users = User::all();\n        return view('dash', compact('users'));\n    }\n}";
        $findings = [['category' => 'select_all', 'file' => 'app/Http/Controllers/DashboardController.php', 'line_start' => 4]];
        $result   = $this->orchestrator->refactorFile('app/Http/Controllers/DashboardController.php', $content, $findings);

        // When not chained with a collection-only method, ::all() is replaced
        $this->assertStringContainsString('paginate(25)', $result->refactored);
        $this->assertGreaterThan(0, $result->autoFixed);
    }

    public function test_refactorFile_does_not_replace_select_all_when_chained_with_count(): void
    {
        $content  = "<?php\nclass ReportController extends Controller {\n    public function index() {\n        \$total = User::all()->count();\n        return \$total;\n    }\n}";
        $findings = [['category' => 'select_all', 'file' => 'app/Http/Controllers/ReportController.php', 'line_start' => 4]];
        $result   = $this->orchestrator->refactorFile('app/Http/Controllers/ReportController.php', $content, $findings);

        // Chained with ->count() — unsafe to replace with paginate()
        $this->assertStringContainsString('::all()', $result->refactored);
        $this->assertStringNotContainsString('paginate(25)', $result->refactored);
    }

    public function test_refactorFile_adds_eager_loading_for_n_plus_one(): void
    {
        $content  = "<?php\nclass OrderController extends Controller {\n    public function index() {\n        foreach (Order::where('status','active')->get() as \$order) {\n            echo \$order->user->name;\n        }\n    }\n}";
        $findings = [[
            'category'     => 'n_plus_one',
            'file'         => 'app/Http/Controllers/OrderController.php',
            'line_start'   => 4,
            'code_snippet' => '$order->user->name',
        ]];
        $result   = $this->orchestrator->refactorFile('app/Http/Controllers/OrderController.php', $content, $findings);

        // Should add ->with('user') before ->get()
        $this->assertStringContainsString("->with('user')", $result->refactored);
        $this->assertGreaterThan(0, $result->autoFixed);
    }

    public function test_refactorFile_parameterizes_sql_injection(): void
    {
        $content = <<<'PHP'
<?php
class UserRepo {
    public function find($id) {
        return DB::select("SELECT * FROM users WHERE id = $id");
    }
}
PHP;
        $findings = [['category' => 'sql_injection', 'file' => 'app/UserRepo.php', 'line_start' => 4, 'code_snippet' => 'DB::select("SELECT * FROM users WHERE id = $id")']];
        $result   = $this->orchestrator->refactorFile('app/UserRepo.php', $content, $findings);

        $this->assertStringContainsString('?', $result->refactored);
        $this->assertStringContainsString('[$id]', $result->refactored);
        $this->assertStringNotContainsString('"SELECT * FROM users WHERE id = $id"', $result->refactored);
        $this->assertGreaterThan(0, $result->autoFixed);
    }

    public function test_refactorFile_fixes_xss_in_blade(): void
    {
        $content  = "<div>{!! \$user->name !!}</div>\n<p>{!! \$post->body !!}</p>";
        $findings = [['category' => 'xss', 'file' => 'resources/views/user.blade.php', 'line_start' => 1]];
        $result   = $this->orchestrator->refactorFile('resources/views/user.blade.php', $content, $findings);

        $this->assertStringNotContainsString('{!!', $result->refactored);
        $this->assertStringContainsString('{{ $user->name }}', $result->refactored);
        $this->assertGreaterThan(0, $result->autoFixed);
    }

    public function test_refactorFile_replaces_hardcoded_secrets_with_env(): void
    {
        $content = <<<'PHP'
<?php
class ApiClient {
    private $password = 'supersecret123';
    private $token = 'abc123xyz456';
}
PHP;
        $findings = [['category' => 'secret_exposure', 'file' => 'app/ApiClient.php', 'line_start' => 3]];
        $result   = $this->orchestrator->refactorFile('app/ApiClient.php', $content, $findings);

        $this->assertStringNotContainsString("'supersecret123'", $result->refactored);
        $this->assertStringContainsString('env(', $result->refactored);
        $this->assertGreaterThan(0, $result->autoFixed);
    }

    public function test_refactorFile_fixes_inefficient_count(): void
    {
        $content  = "<?php\nclass StatsController {\n    public function index() {\n        \$total = count(User::all());\n        return \$total;\n    }\n}";
        $findings = [['category' => 'inefficient_count', 'file' => 'app/StatsController.php', 'line_start' => 4]];
        $result   = $this->orchestrator->refactorFile('app/StatsController.php', $content, $findings);

        $this->assertStringNotContainsString('count(User::all())', $result->refactored);
        $this->assertStringContainsString('User::count()', $result->refactored);
        $this->assertGreaterThan(0, $result->autoFixed);
    }

    public function test_refactorFile_adds_authorization_stubs(): void
    {
        $content = <<<'PHP'
<?php
class PostController extends Controller {
    public function store(Request $request) {
        Post::create($request->validated());
    }
    public function destroy(Post $post) {
        $post->delete();
    }
}
PHP;
        $findings = [['category' => 'authorization', 'file' => 'app/Http/Controllers/PostController.php', 'line_start' => 3]];
        $result   = $this->orchestrator->refactorFile('app/Http/Controllers/PostController.php', $content, $findings);

        $this->assertStringContainsString('$this->authorize(', $result->refactored);
        $this->assertGreaterThan(0, $result->autoFixed);
    }

    public function test_refactorFile_adds_return_types_for_inferable_methods(): void
    {
        $content = <<<'PHP'
<?php
class UserService {
    public function isActive($user) {
        return $user->active;
    }
    public function hasPermission($user) {
        return $user->role === 'admin';
    }
    public function handle() {
        // process
    }
}
PHP;
        $findings = [['category' => 'missing_types', 'file' => 'app/UserService.php', 'line_start' => 3]];
        $result   = $this->orchestrator->refactorFile('app/UserService.php', $content, $findings);

        $this->assertStringContainsString(': bool', $result->refactored);
        $this->assertStringContainsString(': void', $result->refactored);
        $this->assertGreaterThan(0, $result->autoFixed);
    }

    public function test_refactorFile_generates_form_request_for_inline_validation(): void
    {
        $content = <<<'PHP'
<?php
namespace App\Http\Controllers;
class UserController extends Controller {
    public function store(Request $request) {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
        ]);
        User::create($validated);
    }
}
PHP;
        $findings = [['category' => 'solid', 'file' => 'app/Http/Controllers/UserController.php', 'line_start' => 5]];
        $result   = $this->orchestrator->refactorFile('app/Http/Controllers/UserController.php', $content, $findings);

        // A new FormRequest file should be generated
        $this->assertNotEmpty($result->generatedFiles);
        $genPath = array_key_first($result->generatedFiles);
        $this->assertStringContainsString('UserRequest', $genPath);
        $this->assertStringContainsString('FormRequest', $result->generatedFiles[$genPath]);
        $this->assertStringContainsString("'name' => 'required|string|max:255'", $result->generatedFiles[$genPath]);
    }

    public function test_refactorFile_returns_manual_notes_for_fat_controller(): void
    {
        $findings = [['category' => 'fat_controller', 'file' => 'app/Http/Controllers/BigController.php', 'line_start' => 1, 'recommendation' => 'Extract to service']];
        $result   = $this->orchestrator->refactorFile('app/Http/Controllers/BigController.php', 'content', $findings);

        $manuals = array_filter($result->changes, fn($c) => str_starts_with($c, '[MANUAL]'));
        $this->assertNotEmpty($manuals);
    }

    public function test_refactorFile_no_changes_returns_has_changes_false(): void
    {
        $content  = '<?php class Clean {}';
        $result   = $this->orchestrator->refactorFile('app/Clean.php', $content, []);

        $this->assertFalse($result->hasChanges());
    }

    public function test_refactorFile_deduplicates_categories(): void
    {
        // Two findings for the same category should produce ONE [MANUAL] entry
        $findings = [
            ['category' => 'fat_controller', 'file' => 'app/Http/Controllers/X.php', 'line_start' => 1],
            ['category' => 'fat_controller', 'file' => 'app/Http/Controllers/X.php', 'line_start' => 50],
        ];
        $result = $this->orchestrator->refactorFile('app/Http/Controllers/X.php', 'content', $findings);

        $fatChanges = array_filter($result->changes, fn($c) => str_contains($c, 'Fat controller'));
        $this->assertCount(1, $fatChanges, 'Duplicate categories should be de-duplicated');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeDirtyController(): string
    {
        $body = implode("\n", array_fill(0, 60, "        \$x = SomeModel::where('id', 1)->get();"));
        return "<?php\nnamespace App\\Http\\Controllers;\nclass BigController extends Controller\n{\n    public function index(Request \$request)\n    {\n{$body}\n    }\n}";
    }
}
