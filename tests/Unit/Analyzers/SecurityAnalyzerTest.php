<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Analyzers;

use CodeGuardian\Laravel\Analyzers\SecurityAnalyzer;
use CodeGuardian\Laravel\Values\Severity;
use PHPUnit\Framework\TestCase;

class SecurityAnalyzerTest extends TestCase
{
    private SecurityAnalyzer $analyzer;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new SecurityAnalyzer();
        $this->tmpDir   = sys_get_temp_dir() . '/cg_sec_scan_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
        parent::tearDown();
    }

    // ─── Instantiation / regression ──────────────────────────────────────────

    public function test_can_be_instantiated_without_fatal_error(): void
    {
        $this->assertInstanceOf(SecurityAnalyzer::class, $this->analyzer);
    }

    public function test_analyze_returns_array_with_expected_keys(): void
    {
        $file = $this->tmpFile('empty.php', '<?php // empty');

        $result = $this->analyzer->analyze($file);

        $this->assertArrayHasKey('findings', $result);
        $this->assertArrayHasKey('security_score', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertIsArray($result['findings']);
        $this->assertIsInt($result['security_score']);
        $this->assertGreaterThanOrEqual(0, $result['security_score']);
        $this->assertLessThanOrEqual(100, $result['security_score']);
    }

    // ─── SQL injection detection ──────────────────────────────────────────────

    public function test_detects_raw_db_statement_with_user_input(): void
    {
        $file = $this->tmpFile('UserController.php', <<<'PHP'
        <?php
        class UserController {
            public function search($name) {
                $results = DB::statement("SELECT * FROM users WHERE name='$name'");
            }
        }
        PHP);

        $result = $this->analyzer->analyze($file);

        $this->assertFindsCategory($result['findings'], 'sql_injection');
    }

    public function test_detects_raw_db_select_with_string_concat(): void
    {
        $file = $this->tmpFile('OrderController.php', <<<'PHP'
        <?php
        class OrderController {
            public function get($id) {
                return DB::select("SELECT * FROM orders WHERE id=$id");
            }
        }
        PHP);

        $result = $this->analyzer->analyze($file);
        $this->assertFindsCategory($result['findings'], 'sql_injection');
    }

    // ─── Hardcoded secrets ────────────────────────────────────────────────────

    public function test_detects_hardcoded_password(): void
    {
        $file = $this->tmpFile('Config.php', <<<'PHP'
        <?php
        class Config {
            private string $password = 'super_secret_password123';
        }
        PHP);

        $result = $this->analyzer->analyze($file);
        $this->assertFindsCategory($result['findings'], 'secret_exposure');
    }

    public function test_detects_hardcoded_api_key(): void
    {
        $file = $this->tmpFile('PaymentService.php', <<<'PHP'
        <?php
        class PaymentService {
            private string $secret = 'sk-abcdefghijklmnopqrstuvwxyz1234';
        }
        PHP);

        $result = $this->analyzer->analyze($file);
        $this->assertFindsCategory($result['findings'], 'secret_exposure');
    }

    // ─── Missing authorization ─────────────────────────────────────────────────

    public function test_detects_controller_without_authorize_or_middleware(): void
    {
        $file = $this->tmpFile('AdminController.php', <<<'PHP'
        <?php
        class AdminController extends Controller {
            public function store(Request $request) {
                User::create($request->validated());
                return response()->json(['created' => true]);
            }
            public function update(Request $request, $id) {
                User::find($id)->update($request->validated());
                return response()->json(['updated' => true]);
            }
            public function destroy($id) {
                User::find($id)->delete();
                return response()->json(['deleted' => true]);
            }
        }
        PHP);

        $result = $this->analyzer->analyze($file);
        $this->assertFindsCategory($result['findings'], 'authorization');
    }

    // ─── Mass assignment ──────────────────────────────────────────────────────

    public function test_detects_fill_all_with_user_input(): void
    {
        $file = $this->tmpFile('ProfileController.php', <<<'PHP'
        <?php
        class ProfileController extends Controller {
            public function update(Request $request, $id) {
                $user = User::find($id);
                $user->fill($request->all());
                $user->save();
                return response()->json($user);
            }
        }
        PHP);

        $result = $this->analyzer->analyze($file);
        $this->assertFindsCategory($result['findings'], 'mass_assignment');
    }

    // ─── Debug code left ──────────────────────────────────────────────────────

    public function test_detects_dd_in_controller(): void
    {
        $file = $this->tmpFile('DebugController.php', <<<'PHP'
        <?php
        class DebugController extends Controller {
            public function show($id) {
                $user = User::find($id);
                dd($user);
                return response()->json($user);
            }
        }
        PHP);

        $result = $this->analyzer->analyze($file);
        $this->assertFindsCategory($result['findings'], 'debug_code');
    }

    // ─── Clean code has high score ────────────────────────────────────────────

    public function test_clean_file_gets_high_score(): void
    {
        $file = $this->tmpFile('CleanService.php', <<<'PHP'
        <?php
        namespace App\Services;

        class CleanService {
            public function __construct(
                private readonly UserRepository $users
            ) {}

            public function getUser(int $id): ?User {
                return $this->users->find($id);
            }
        }
        PHP);

        $result = $this->analyzer->analyze($file);

        $this->assertGreaterThanOrEqual(80, $result['security_score'],
            'A clean file should score at least 80'
        );
    }

    public function test_score_decreases_with_more_critical_findings(): void
    {
        // File with multiple serious issues
        $dirtyFile = $this->tmpFile('DirtyController.php', <<<'PHP'
        <?php
        class DirtyController {
            private string $secret = 'hardcoded_api_key_12345';
            public function run($input) {
                DB::statement("SELECT * FROM users WHERE id=" . $input);
                dd($input);
                $u = User::first();
                $u->fill($_POST);
                $u->save();
            }
        }
        PHP);

        // Clean file
        $cleanFile = $this->tmpFile('CleanService2.php', '<?php class CleanService2 {}');

        $dirtyResult = $this->analyzer->analyze($dirtyFile);
        $cleanResult = $this->analyzer->analyze($cleanFile);

        $this->assertLessThan($cleanResult['security_score'], $dirtyResult['security_score'],
            'Dirty file must score lower than clean file'
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Write a temp file and return a [path => content] map suitable for analyzer->analyze().
     */
    private function tmpFile(string $name, string $content): array
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return [$path => $content];
    }

    private function assertFindsCategory(array $findings, string $category): void
    {
        $categories = array_column($findings, 'category');
        $this->assertContains(
            $category,
            $categories,
            "Expected to find category '{$category}' but only found: " . implode(', ', $categories)
        );
    }

    private function rmdirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
