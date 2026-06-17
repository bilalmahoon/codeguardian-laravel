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

    /**
     * select_all is intentionally NOT auto-replaced with paginate(25) because replacing
     * ::all() with paginate() changes the return type from Collection to LengthAwarePaginator
     * and would break callers. Instead, a CODEGUARDIAN-FIX inline comment is inserted so
     * the developer reviews it manually — zero risk of silent behaviour change.
     */
    public function test_refactorFile_inserts_hint_comment_for_select_all_not_auto_paginate(): void
    {
        $content  = "<?php\nclass DashboardController extends Controller {\n    public function index() {\n        \$users = User::all();\n        return view('dash', compact('users'));\n    }\n}";
        $findings = [['category' => 'select_all', 'file' => 'app/Http/Controllers/DashboardController.php', 'line_start' => 4]];
        $result   = $this->orchestrator->refactorFile('app/Http/Controllers/DashboardController.php', $content, $findings);

        // The original ::all() call must remain unchanged (no silent rewrite)
        $this->assertStringContainsString('::all()', $result->refactored,
            'select_all must NOT be auto-replaced — it would silently change return type'
        );
        // paginate(25) must NOT appear — we never auto-replace
        $this->assertStringNotContainsString('paginate(25)', $result->refactored,
            'select_all must never be auto-replaced with paginate(25)'
        );
        // A [MANUAL] note must appear in the changes array so developers see the issue
        $manualNotes = implode(' ', $result->changes);
        $this->assertStringContainsString('select_all', $manualNotes,
            'A [MANUAL] select_all guidance message must be added to $result->changes'
        );
    }

    public function test_refactorFile_inserts_inline_comment_for_n_plus_one(): void
    {
        $content  = "<?php\nclass OrderController extends Controller {\n    public function index() {\n        foreach (Order::get() as \$order) {\n            echo \$order->user->name;\n        }\n    }\n}";
        $findings = [['category' => 'n_plus_one', 'file' => 'app/Http/Controllers/OrderController.php', 'line_start' => 4]];
        $result   = $this->orchestrator->refactorFile('app/Http/Controllers/OrderController.php', $content, $findings);

        $this->assertStringContainsString('CODEGUARDIAN-FIX', $result->refactored);
        $this->assertStringContainsString('N+1 QUERY', $result->refactored);
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
