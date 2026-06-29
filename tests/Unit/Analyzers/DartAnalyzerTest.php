<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Analyzers;

use CodeGuardian\Laravel\Analyzers\DartAnalyzer;
use PHPUnit\Framework\TestCase;

class DartAnalyzerTest extends TestCase
{
    private DartAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new DartAnalyzer();
    }

    private function categories(array $files): array
    {
        return array_column($this->analyzer->analyze($files)['findings'], 'category');
    }

    public function test_ignores_non_dart_files(): void
    {
        $result = $this->analyzer->analyze(['app/Foo.php' => '<?php print("x");']);
        $this->assertSame([], $result['findings']);
        $this->assertSame(100, $result['dart_score']);
    }

    public function test_flags_print(): void
    {
        $files = ['lib/main.dart' => "void main() {\n  print('hello');\n}"];
        $this->assertContains('dart_print', $this->categories($files));
    }

    public function test_flags_setstate_in_build(): void
    {
        $files = ['lib/widget.dart' => <<<'DART'
class MyWidget extends StatefulWidget {
  Widget build(BuildContext context) {
    setState(() { counter++; });
    return Container();
  }
}
DART];

        $this->assertContains('setstate_in_build', $this->categories($files));
    }

    public function test_flags_context_after_await_without_mounted_guard(): void
    {
        $files = ['lib/page.dart' => <<<'DART'
Future<void> save() async {
  await repository.persist();
  Navigator.of(context).pop();
}
DART];

        $this->assertContains('context_after_await', $this->categories($files));
    }

    public function test_mounted_guard_silences_context_after_await(): void
    {
        $files = ['lib/page.dart' => <<<'DART'
Future<void> save() async {
  await repository.persist();
  if (!context.mounted) return;
  Navigator.of(context).pop();
}
DART];

        $this->assertNotContains('context_after_await', $this->categories($files));
    }

    public function test_flags_large_build_method(): void
    {
        $body = str_repeat("    children.add(Text('row'));\n", 85);
        $files = ['lib/big.dart' => "class Big extends StatelessWidget {\n  Widget build(BuildContext context) {\n{$body}    return Column();\n  }\n}"];

        $this->assertContains('large_build_method', $this->categories($files));
    }
}
