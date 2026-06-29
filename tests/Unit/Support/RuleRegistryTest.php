<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\RuleRegistry;
use PHPUnit\Framework\TestCase;

class RuleRegistryTest extends TestCase
{
    public function test_from_config_parses_all_value_shapes(): void
    {
        $spec = RuleRegistry::fromConfig([
            'magic_numbers' => false,
            'missing_types' => 'low',
            'n_plus_one'    => 'critical',
            'todo_debt'     => ['enabled' => true, 'severity' => 'low'],
            'dead_code'     => ['enabled' => false],
            'unknown_token' => 'nonsense',
        ]);

        $this->assertFalse($spec['magic_numbers']['enabled']);
        $this->assertSame('low', $spec['missing_types']['severity']);
        $this->assertSame('critical', $spec['n_plus_one']['severity']);
        $this->assertTrue($spec['todo_debt']['enabled']);
        $this->assertSame('low', $spec['todo_debt']['severity']);
        $this->assertFalse($spec['dead_code']['enabled']);
        $this->assertTrue($spec['unknown_token']['enabled']);
        $this->assertNull($spec['unknown_token']['severity']);
    }

    public function test_off_tokens_disable(): void
    {
        foreach (['off', 'false', 'disabled', 'no', '0'] as $token) {
            $spec = RuleRegistry::fromConfig(['x' => $token]);
            $this->assertFalse($spec['x']['enabled'], "token {$token} should disable");
        }
    }

    private function results(): array
    {
        return [
            'all_findings' => [
                ['category' => 'magic_numbers', 'severity' => 'medium', 'file' => 'a.php'],
                ['category' => 'n_plus_one', 'severity' => 'high', 'file' => 'b.php'],
                ['category' => 'sql_injection', 'severity' => 'critical', 'file' => 'c.php'],
            ],
            'agent_results' => [
                'tech_debt'   => ['findings' => [['category' => 'magic_numbers', 'severity' => 'medium', 'file' => 'a.php']]],
                'performance' => ['findings' => [['category' => 'n_plus_one', 'severity' => 'high', 'file' => 'b.php']]],
            ],
            'summary' => ['total_issues' => 3, 'critical' => 1, 'high' => 1, 'medium' => 1, 'low' => 0],
        ];
    }

    public function test_apply_disables_and_remaps(): void
    {
        $spec = RuleRegistry::fromConfig([
            'magic_numbers' => false,
            'n_plus_one'    => 'critical',
        ]);

        [$out, $disabled, $remapped] = RuleRegistry::applyToResult($this->results(), $spec);

        $this->assertSame(1, $disabled);
        $this->assertSame(1, $remapped);
        $this->assertCount(2, $out['all_findings']);
        $this->assertSame(2, $out['summary']['total_issues']);
        $this->assertSame(2, $out['summary']['critical']); // n_plus_one upgraded + sql_injection
        $this->assertSame(0, $out['summary']['high']);
        $this->assertCount(0, $out['agent_results']['tech_debt']['findings']);
    }

    public function test_empty_spec_is_noop(): void
    {
        [$out, $disabled, $remapped] = RuleRegistry::applyToResult($this->results(), []);
        $this->assertSame(0, $disabled);
        $this->assertSame(0, $remapped);
        $this->assertCount(3, $out['all_findings']);
    }

    public function test_describe_merges_catalog_with_overrides(): void
    {
        $spec = RuleRegistry::fromConfig(['magic_numbers' => false, 'n_plus_one' => 'critical']);
        $rows = RuleRegistry::describe($spec);

        $this->assertSame(count(RuleRegistry::CATALOG), count($rows));

        $byId = [];
        foreach ($rows as $r) {
            $byId[$r['id']] = $r;
        }
        $this->assertFalse($byId['magic_numbers']['enabled']);
        $this->assertSame('critical', $byId['n_plus_one']['severity']);
        $this->assertSame('default', $byId['sql_injection']['severity']);
        $this->assertTrue($byId['sql_injection']['enabled']);
    }
}
