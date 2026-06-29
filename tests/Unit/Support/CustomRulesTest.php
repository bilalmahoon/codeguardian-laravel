<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\CustomRules;
use PHPUnit\Framework\TestCase;

class CustomRulesTest extends TestCase
{
    public function test_from_config_drops_invalid_rules(): void
    {
        $specs = CustomRules::fromConfig([
            ['id' => 'good', 'title' => 'Good', 'pattern' => 'foo'],
            ['id' => '', 'title' => 'No id', 'pattern' => 'x'],     // dropped
            ['id' => 'nopattern', 'title' => 'X'],                   // dropped
            ['id' => 'badregex', 'title' => 'X', 'pattern' => '('],  // dropped (won't compile)
            'not-an-array',                                          // dropped
        ]);

        $this->assertCount(1, $specs);
        $this->assertSame('good', $specs[0]['id']);
        $this->assertSame('medium', $specs[0]['severity']); // default
    }

    public function test_run_emits_findings_with_line_numbers(): void
    {
        $specs = CustomRules::fromConfig([[
            'id' => 'no_env', 'title' => 'env() outside config',
            'pattern' => '\\benv\\(', 'severity' => 'high', 'fix' => 'use config()',
        ]]);

        $files = ['app/Foo.php' => "<?php\n\$a = 1;\n\$b = env('X');\n"];
        $findings = CustomRules::run($files, $specs);

        $this->assertCount(1, $findings);
        $this->assertSame('no_env', $findings[0]['category']);
        $this->assertSame('high', $findings[0]['severity']);
        $this->assertSame(3, $findings[0]['line_start']);
        $this->assertSame('use config()', $findings[0]['recommendation']);
        $this->assertSame('custom_rule', $findings[0]['source']);
    }

    public function test_paths_and_exclude_filters(): void
    {
        $specs = CustomRules::fromConfig([[
            'id' => 'r', 'title' => 'r', 'pattern' => 'env\\(',
            'paths' => ['app/'], 'exclude' => ['config/'],
        ]]);

        $files = [
            'app/A.php'    => "<?php env('A');",
            'config/x.php' => "<?php env('B');", // excluded
            'src/B.php'    => "<?php env('C');", // not in paths
        ];

        $findings = CustomRules::run($files, $specs);
        $this->assertCount(1, $findings);
        $this->assertSame('app/A.php', $findings[0]['file']);
    }
}
