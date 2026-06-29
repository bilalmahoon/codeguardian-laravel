<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Analyzers;

use CodeGuardian\Laravel\Analyzers\AnalysisResult;
use CodeGuardian\Laravel\Analyzers\ArchitectureAnalyzer;
use CodeGuardian\Laravel\Analyzers\PerformanceAnalyzer;
use CodeGuardian\Laravel\Analyzers\TechDebtAnalyzer;
use PHPUnit\Framework\TestCase;

class ExtendedChecksTest extends TestCase
{
    private function cats(array $result): array
    {
        return array_column($result['findings'], 'category');
    }

    // ── AnalysisResult metadata contract ──────────────────────────────────────

    public function test_analysis_result_serialises_enrichment_metadata(): void
    {
        $r = AnalysisResult::make(
            category: 'sql_injection', severity: 'critical', title: 't', description: 'd', file: 'f.php',
            confidence: 'high', impact: 'RCE', effort: 'small', breakingRisk: 'low',
            rootCause: 'taint', cwe: 'CWE-89', owasp: 'A03:2021-Injection', principle: 'SOLID:SRP',
        )->toArray();

        $this->assertSame('high', $r['confidence']);
        $this->assertSame('RCE', $r['impact']);
        $this->assertSame('small', $r['effort']);
        $this->assertSame('low', $r['breaking_risk']);
        $this->assertSame('taint', $r['root_cause']);
        $this->assertSame('CWE-89', $r['cwe']);
        $this->assertSame('A03:2021-Injection', $r['owasp']);
        $this->assertSame('SOLID:SRP', $r['principle']);
    }

    public function test_metadata_defaults_are_backward_compatible(): void
    {
        $r = AnalysisResult::make('x', 'low', 't', 'd', 'f.php')->toArray();
        $this->assertSame('medium', $r['confidence']);
        $this->assertSame('', $r['cwe']);
        $this->assertSame([], $r['references']);
    }

    // ── Performance ────────────────────────────────────────────────────────────

    public function test_performance_detects_query_in_loop(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App\Http\Controllers;
        class C extends Controller {
            public function index() {
                $out = [];
                foreach ($ids as $id) {
                    $out[] = User::find($id);
                }
                return $out;
            }
        }
        PHP;
        $result = (new PerformanceAnalyzer())->analyze(['app/Http/Controllers/C.php' => $code]);
        $this->assertContains('query_in_loop', $this->cats($result));
    }

    public function test_performance_detects_over_fetching(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App\Http\Controllers;
        class C extends Controller {
            public function index() {
                return User::all()->where('active', true);
            }
        }
        PHP;
        $result = (new PerformanceAnalyzer())->analyze(['app/Http/Controllers/C.php' => $code]);
        $this->assertContains('over_fetching', $this->cats($result));
    }

    public function test_performance_detects_nested_loops(): void
    {
        $code = <<<'PHP'
        <?php
        class C {
            public function run($a, $b) {
                foreach ($a as $x) {
                    foreach ($b as $y) {
                        echo $x . $y;
                    }
                }
            }
        }
        PHP;
        $result = (new PerformanceAnalyzer())->analyze(['C.php' => $code]);
        $this->assertContains('nested_loops', $this->cats($result));
    }

    public function test_performance_sequential_loops_are_not_nested(): void
    {
        $code = <<<'PHP'
        <?php
        class C {
            public function run($a, $b) {
                foreach ($a as $x) { echo $x; }
                foreach ($b as $y) { echo $y; }
            }
        }
        PHP;
        $result = (new PerformanceAnalyzer())->analyze(['C.php' => $code]);
        $this->assertNotContains('nested_loops', $this->cats($result));
    }

    // ── Tech debt ───────────────────────────────────────────────────────────────

    public function test_techdebt_detects_long_parameter_list(): void
    {
        $code = "<?php class C { public function make(\$a, \$b, \$c, \$d, \$e, \$f) {} }";
        $result = (new TechDebtAnalyzer())->analyze(['C.php' => $code]);
        $this->assertContains('long_parameter_list', $this->cats($result));
    }

    public function test_techdebt_detects_empty_catch(): void
    {
        $code = <<<'PHP'
        <?php
        class C {
            public function run() {
                try { risky(); } catch (\Throwable $e) {}
            }
        }
        PHP;
        $result = (new TechDebtAnalyzer())->analyze(['C.php' => $code]);
        $this->assertContains('swallowed_exception', $this->cats($result));
    }

    public function test_techdebt_detects_god_class(): void
    {
        $methods = '';
        for ($i = 0; $i < 22; $i++) {
            $methods .= "    public function m{$i}() { return {$i}; }\n";
        }
        $code = "<?php class Huge {\n{$methods}}";
        $result = (new TechDebtAnalyzer())->analyze(['Huge.php' => $code]);
        $this->assertContains('god_class', $this->cats($result));
    }

    // ── Architecture ──────────────────────────────────────────────────────────

    public function test_architecture_flags_env_outside_config(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App\Services;
        class PayService {
            public function key() { return env('STRIPE_KEY'); }
        }
        PHP;
        $result = (new ArchitectureAnalyzer())->analyze(['app/Services/PayService.php' => $code]);
        $this->assertContains('config_misuse', $this->cats($result));
    }

    public function test_architecture_allows_env_inside_config(): void
    {
        $code = "<?php return ['key' => env('STRIPE_KEY')];";
        $result = (new ArchitectureAnalyzer())->analyze(['config/services.php' => $code]);
        $this->assertNotContains('config_misuse', $this->cats($result));
    }
}
