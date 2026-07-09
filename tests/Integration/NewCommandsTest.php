<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Integration;

use Orchestra\Testbench\TestCase;

/**
 * Boot-level smoke tests for the commands added in the "do all" batch. These
 * confirm the commands register and run end-to-end inside a real Laravel app
 * (not just that their pure helpers work).
 */
class NewCommandsTest extends TestCase
{
    private string $dir;

    protected function getPackageProviders($app): array
    {
        return [\CodeGuardian\Laravel\CodeGuardianServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cg-newcmd-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
        parent::tearDown();
    }

    public function test_audit_fails_cleanly_without_composer_lock(): void
    {
        $this->artisan("codeguardian:audit --path={$this->dir}")
            ->assertExitCode(1);
    }

    public function test_graph_handles_non_modular_project(): void
    {
        $this->artisan("codeguardian:graph --path={$this->dir}")
            ->assertExitCode(0);
    }

    public function test_graph_detects_module_cycles(): void
    {
        // Two modules that import each other → a circular dependency.
        $this->writeFile('Modules/Order/OrderService.php', "<?php\nnamespace Modules\\Order;\nuse Modules\\Payment\\Pay;\nclass OrderService {}");
        $this->writeFile('Modules/Payment/Pay.php', "<?php\nnamespace Modules\\Payment;\nuse Modules\\Order\\OrderService;\nclass Pay {}");

        $this->artisan("codeguardian:graph --path={$this->dir} --fail-on-cycles")
            ->assertExitCode(1);
    }

    public function test_explain_known_rule(): void
    {
        $this->artisan('codeguardian:explain n_plus_one')->assertExitCode(0);
    }

    public function test_config_check_passes_on_defaults(): void
    {
        $this->artisan('codeguardian:config-check')->assertExitCode(0);
    }

    public function test_config_check_fails_on_bad_mode(): void
    {
        config()->set('codeguardian.mode', 'turbo');
        $this->artisan('codeguardian:config-check')->assertExitCode(1);
    }

    public function test_notify_dry_run_prints_payload(): void
    {
        $dir = $this->dir . '/reports';
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/report.json', json_encode([
            'project_name' => 'demo',
            'overall_score' => 80,
            'grade' => 'B',
            'summary' => ['total_issues' => 0],
        ]));

        $this->artisan("codeguardian:notify --report={$dir}/report.json --format=generic --dry-run")
            ->assertExitCode(0);
    }

    public function test_explicit_static_mode_never_upgrades_to_ai(): void
    {
        // A configured AI key used to silently upgrade even an explicit
        // --mode=static to hybrid and spend money. It must now stay static.
        config()->set('codeguardian.provider', 'claude');
        config()->set('codeguardian.claude.key', 'sk-ant-test-key');

        $this->writeFile('app/Http/Controllers/HomeController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
class HomeController extends Controller { public function index() { return 1; } }
PHP);

        $this->artisan("codeguardian:analyze --path={$this->dir} --plain --no-report --mode=static")
            ->expectsOutputToContain('Static engine')
            ->assertExitCode(0);
    }

    public function test_analyze_succeeds_by_default_even_with_a_critical_finding(): void
    {
        // Guaranteed critical (raw SQL with variable interpolation).
        $this->writeFile('app/Http/Controllers/ReportController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
class ReportController extends Controller {
    public function show($id) { return DB::select("SELECT * FROM users WHERE id = $id"); }
}
PHP);

        // Default config: a completed analysis is a success (dashboard shows
        // "completed"), even though it found a critical issue.
        $this->artisan("codeguardian:analyze --path={$this->dir} --plain --no-report --mode=static")
            ->expectsOutputToContain('Critical:')
            ->assertExitCode(0);
    }

    public function test_analyze_fails_on_critical_when_opted_in(): void
    {
        config()->set('codeguardian.analysis.fail_on_critical', true);

        $this->writeFile('app/Http/Controllers/ReportController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
class ReportController extends Controller {
    public function show($id) { return DB::select("SELECT * FROM users WHERE id = $id"); }
}
PHP);

        $this->artisan("codeguardian:analyze --path={$this->dir} --plain --no-report --mode=static")
            ->assertExitCode(1);
    }

    public function test_refactor_engine_static_overrides_hybrid_config(): void
    {
        // Config says hybrid + a key is present — but --engine=static must win
        // and keep the refactor AI-free (no Claude calls, no cost).
        config()->set('codeguardian.mode', 'hybrid');
        config()->set('codeguardian.provider', 'claude');
        config()->set('codeguardian.claude.key', 'sk-ant-test-key');

        $this->artisan("codeguardian:refactor --path={$this->dir} --engine=static --mode=auto --skip-tests")
            ->expectsOutputToContain('Static only')
            ->assertExitCode(0);
    }

    public function test_security_scan_explicit_static_mode_stays_static(): void
    {
        config()->set('codeguardian.provider', 'claude');
        config()->set('codeguardian.claude.key', 'sk-ant-test-key');

        $this->writeFile('app/Http/Controllers/CleanController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
class CleanController extends Controller { public function index(): int { return 1; } }
PHP);

        $this->artisan("codeguardian:security --path={$this->dir} --plain --mode=static --output={$this->dir}/rep")
            ->expectsOutputToContain('Static engine')
            ->assertExitCode(0);
    }

    public function test_performance_scan_explicit_static_mode_stays_static(): void
    {
        config()->set('codeguardian.provider', 'claude');
        config()->set('codeguardian.claude.key', 'sk-ant-test-key');

        $this->writeFile('app/Http/Controllers/CleanController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
class CleanController extends Controller { public function index(): int { return 1; } }
PHP);

        $this->artisan("codeguardian:performance --path={$this->dir} --plain --mode=static --output={$this->dir}/rep")
            ->expectsOutputToContain('Static engine')
            ->assertExitCode(0);
    }

    public function test_generate_tests_explicit_static_mode_stays_static(): void
    {
        config()->set('codeguardian.provider', 'claude');
        config()->set('codeguardian.claude.key', 'sk-ant-test-key');

        $this->writeFile('app/Http/Controllers/CleanController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
class CleanController extends Controller { public function index(): int { return 1; } }
PHP);

        $this->artisan("codeguardian:test --path={$this->dir} --dry-run --mode=static")
            ->expectsOutputToContain('Static engine')
            ->assertExitCode(0);
    }

    public function test_analyze_quality_gate_fails_when_budget_breached(): void
    {
        config()->set('codeguardian.gates', ['max_total' => 0]);
        config()->set('codeguardian.mode', 'static');

        // A dirty controller guarantees at least one finding → breaches max_total=0.
        $this->writeFile('app/Http/Controllers/DirtyController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
class DirtyController extends Controller {
    public function store(\Illuminate\Http\Request $request) {
        \App\Models\User::create($request->all());
        dd($request);
    }
}
PHP);

        $this->artisan("codeguardian:analyze --path={$this->dir} --plain --no-report --mode=static")
            ->assertExitCode(1);
    }

    private function writeFile(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
