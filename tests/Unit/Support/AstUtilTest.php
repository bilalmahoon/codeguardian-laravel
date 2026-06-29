<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\AstUtil;
use CodeGuardian\Laravel\Support\CachedPhpParser;
use PHPUnit\Framework\TestCase;

class AstUtilTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CachedPhpParser::flush();
    }

    public function test_complexity_counts_decision_points(): void
    {
        $code = <<<'PHP'
        <?php
        function f($a, $b) {
            if ($a && $b) {            // if + &&  → +2
                foreach ([1,2] as $x) { // foreach → +1
                    echo $x;
                }
            } elseif ($a || $b) {       // elseif + || → +2
                return $a ?? $b;        // ?? → +1
            }
            return 0;
        }
        PHP;

        // decision points: if, &&, foreach, elseif, ||, ?? = 6  → base 1 + 6 = 7
        $this->assertSame(7, AstUtil::complexity($code));
    }

    public function test_complexity_ignores_keywords_in_strings(): void
    {
        // "if" inside a string must NOT count — this is the AST advantage.
        $code = '<?php function f() { return "if for while && ||"; }';
        $this->assertSame(1, AstUtil::complexity($code));
    }

    public function test_invalid_php_returns_null(): void
    {
        $this->assertNull(AstUtil::complexity('<?php function ( {{{ broken'));
    }

    public function test_methods_returns_per_method_metrics(): void
    {
        $code = <<<'PHP'
        <?php
        class C {
            public function simple() { return 1; }
            public function complex($x) {
                if ($x) { return 1; }
                return 2;
            }
        }
        PHP;

        $methods = AstUtil::methods($code);
        $this->assertNotNull($methods);
        $this->assertCount(2, $methods);

        $byName = [];
        foreach ($methods as $m) { $byName[$m['name']] = $m; }

        $this->assertSame(1, $byName['simple']['complexity']);
        $this->assertSame(2, $byName['complex']['complexity']);
        $this->assertSame(1, $byName['complex']['params']);
    }
}
