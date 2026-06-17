<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Analyzers;

use CodeGuardian\Laravel\Analyzers\ArchitectureAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CodeGuardian\Laravel\Analyzers\ArchitectureAnalyzer
 */
class ArchitectureAnalyzerTest extends TestCase
{
    private ArchitectureAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ArchitectureAnalyzer();
    }

    // ── Fat controller ───────────────────────────────────────────────────────

    public function test_fat_controller_is_detected(): void
    {
        $file    = 'app/Http/Controllers/OrderController.php';
        $content = $this->makeFatController(200);

        $result   = $this->analyzer->analyze([$file => $content]);
        $cats     = array_column($result['findings'], 'category');

        $this->assertContains('fat_controller', $cats,
            'Expected fat_controller finding for a 200-line controller');
    }

    public function test_small_controller_has_no_fat_controller_finding(): void
    {
        $file    = 'app/Http/Controllers/HealthController.php';
        $content = $this->makeSmallController();

        $result = $this->analyzer->analyze([$file => $content]);
        $cats   = array_column($result['findings'], 'category');

        $this->assertNotContains('fat_controller', $cats);
    }

    // ── Direct DB access ─────────────────────────────────────────────────────

    public function test_direct_db_access_in_controller_is_detected(): void
    {
        $file    = 'app/Http/Controllers/ReportController.php';
        $content = <<<'PHP'
<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::where('status', 'active')->get();
        return response()->json($orders);
    }
}
PHP;

        $result = $this->analyzer->analyze([$file => $content]);
        $cats   = array_column($result['findings'], 'category');

        $this->assertContains('service_layer', $cats,
            'Expected service_layer finding when controller queries DB directly');
    }

    public function test_controller_with_service_injection_passes(): void
    {
        $file    = 'app/Http/Controllers/UserController.php';
        $content = <<<'PHP'
<?php
namespace App\Http\Controllers;

class UserController extends Controller
{
    public function __construct(private UserService $service) {}

    public function index()
    {
        return $this->service->getAll();
    }
}
PHP;

        $result = $this->analyzer->analyze([$file => $content]);
        $cats   = array_column($result['findings'], 'category');

        $this->assertNotContains('service_layer', $cats,
            'Controller that injects a Service should NOT trigger service_layer finding');
    }

    // ── Custom model names (H2 regression) ──────────────────────────────────

    public function test_custom_model_names_are_detected_as_direct_db_access(): void
    {
        $file    = 'app/Http/Controllers/InvoiceController.php';
        $content = <<<'PHP'
<?php
namespace App\Http\Controllers;

class InvoiceController extends Controller
{
    public function show($id)
    {
        $invoice = Invoice::findOrFail($id);
        return response()->json($invoice);
    }
}
PHP;

        $result = $this->analyzer->analyze([$file => $content]);
        $cats   = array_column($result['findings'], 'category');

        $this->assertContains('service_layer', $cats,
            'Custom model names (Invoice) must be caught — not just hardcoded list');
    }

    // ── Inline validation ────────────────────────────────────────────────────

    public function test_inline_validation_is_detected(): void
    {
        $file    = 'app/Http/Controllers/PostController.php';
        $content = <<<'PHP'
<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['title' => 'required|string']);
        // ...
    }
}
PHP;

        $result = $this->analyzer->analyze([$file => $content]);
        $cats   = array_column($result['findings'], 'category');

        $this->assertContains('solid', $cats);
    }

    // ── Score calculation ─────────────────────────────────────────────────────

    public function test_clean_file_scores_100(): void
    {
        $file    = 'app/Http/Controllers/PingController.php';
        $content = <<<'PHP'
<?php
namespace App\Http\Controllers;

class PingController extends Controller
{
    public function __invoke(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
PHP;

        $result = $this->analyzer->analyze([$file => $content]);
        $this->assertSame(100, $result['architecture_score']);
    }

    public function test_score_is_between_0_and_100(): void
    {
        $result = $this->analyzer->analyze([
            'app/Http/Controllers/BigController.php' => $this->makeFatController(400),
        ]);

        $this->assertGreaterThanOrEqual(0, $result['architecture_score']);
        $this->assertLessThanOrEqual(100, $result['architecture_score']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeFatController(int $lines): string
    {
        $methods = implode("\n", array_map(
            fn($i) => "    public function method{$i}(\$x)\n    {\n        return \$x + {$i};\n    }",
            range(1, (int) ($lines / 5))
        ));

        return "<?php\nnamespace App\\Http\\Controllers;\nclass BigController extends Controller\n{\n{$methods}\n}";
    }

    private function makeSmallController(): string
    {
        return <<<'PHP'
<?php
namespace App\Http\Controllers;

class HealthController extends Controller
{
    public function __invoke(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
PHP;
    }
}
