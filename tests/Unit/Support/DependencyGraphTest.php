<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\DependencyGraph;
use PHPUnit\Framework\TestCase;

class DependencyGraphTest extends TestCase
{
    public function test_build_resolves_module_edges_from_use_imports(): void
    {
        $files = [
            'Modules/Order/Services/OrderService.php' => "<?php\nuse Modules\\Payment\\PaymentGateway;\nuse Modules\\User\\User;\n",
            'Modules/Payment/PaymentGateway.php'      => "<?php\nuse Illuminate\\Support\\Str;\n",
            'Modules/User/User.php'                   => "<?php\n",
        ];

        $graph = DependencyGraph::build($files, ['Order', 'Payment', 'User']);

        $this->assertSame(['Payment', 'User'], $graph['Order']);
        $this->assertSame([], $graph['Payment']);
        $this->assertSame([], $graph['User']);
    }

    public function test_module_of_matches_path_segment(): void
    {
        $this->assertSame('Order', DependencyGraph::moduleOf('Modules/Order/Foo.php', ['Order', 'User']));
        $this->assertNull(DependencyGraph::moduleOf('app/Http/Kernel.php', ['Order', 'User']));
    }

    public function test_cycles_detects_circular_dependency(): void
    {
        $graph = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A'],
        ];

        $cycles = DependencyGraph::cycles($graph);

        $this->assertCount(1, $cycles);
        $this->assertContains('A', $cycles[0]);
        $this->assertContains('B', $cycles[0]);
        $this->assertContains('C', $cycles[0]);
    }

    public function test_no_cycles_for_acyclic_graph(): void
    {
        $graph = ['A' => ['B'], 'B' => ['C'], 'C' => []];
        $this->assertSame([], DependencyGraph::cycles($graph));
    }

    public function test_mermaid_marks_cycle_edges(): void
    {
        $graph  = ['A' => ['B'], 'B' => ['A']];
        $cycles = DependencyGraph::cycles($graph);
        $mermaid = DependencyGraph::toMermaid($graph, $cycles);

        $this->assertStringContainsString('graph LR', $mermaid);
        $this->assertStringContainsString('cycle', $mermaid);
    }

    public function test_dot_output_is_valid_digraph(): void
    {
        $dot = DependencyGraph::toDot(['A' => ['B'], 'B' => []]);
        $this->assertStringContainsString('digraph dependencies {', $dot);
        $this->assertStringContainsString('"A" -> "B";', $dot);
    }
}
