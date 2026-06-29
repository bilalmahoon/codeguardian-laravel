<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\Baseline;
use PHPUnit\Framework\TestCase;

class BaselineTest extends TestCase
{
    private function finding(array $over = []): array
    {
        return array_merge([
            'category'     => 'sql_injection',
            'severity'     => 'critical',
            'title'        => 'SQL injection',
            'file'         => 'app/X.php',
            'line_start'   => 10,
            'code_snippet' => 'DB::select($q);',
        ], $over);
    }

    public function test_fingerprint_is_stable_across_line_changes(): void
    {
        $a = Baseline::fingerprint($this->finding(['line_start' => 10]));
        $b = Baseline::fingerprint($this->finding(['line_start' => 99]));
        $this->assertSame($a, $b, 'line number must not affect fingerprint');
    }

    public function test_fingerprint_changes_with_category_or_snippet(): void
    {
        $base = Baseline::fingerprint($this->finding());
        $this->assertNotSame($base, Baseline::fingerprint($this->finding(['category' => 'xss'])));
        $this->assertNotSame($base, Baseline::fingerprint($this->finding(['code_snippet' => 'other();'])));
        $this->assertNotSame($base, Baseline::fingerprint($this->finding(['file' => 'app/Y.php'])));
    }

    public function test_snippet_whitespace_normalised(): void
    {
        $a = Baseline::fingerprint($this->finding(['code_snippet' => 'DB::select($q);']));
        $b = Baseline::fingerprint($this->finding(['code_snippet' => "  DB::select(\$q);  \n"]));
        $this->assertSame($a, $b);
    }

    public function test_create_builds_document(): void
    {
        $doc = Baseline::create([$this->finding(), $this->finding(['category' => 'xss', 'title' => 'XSS'])]);

        $this->assertSame(Baseline::VERSION, $doc['version']);
        $this->assertSame(2, $doc['count']);
        $this->assertCount(2, $doc['fingerprints']);
    }

    public function test_diff_partitions_new_existing_fixed(): void
    {
        $old = $this->finding();
        $baseline = Baseline::create([$old]);

        $current = [
            $this->finding(),                                   // existing
            $this->finding(['category' => 'xss', 'title' => 'XSS', 'code_snippet' => 'echo $x;']), // new
        ];

        $diff = Baseline::diff($current, $baseline);

        $this->assertCount(1, $diff['new']);
        $this->assertCount(1, $diff['existing']);
        $this->assertCount(0, $diff['fixed']);
        $this->assertSame('xss', $diff['new'][0]['category']);
    }

    public function test_diff_detects_fixed(): void
    {
        $baseline = Baseline::create([
            $this->finding(),
            $this->finding(['category' => 'xss', 'title' => 'XSS', 'code_snippet' => 'echo $x;']),
        ]);

        $diff = Baseline::diff([$this->finding()], $baseline);

        $this->assertCount(0, $diff['new']);
        $this->assertCount(1, $diff['existing']);
        $this->assertCount(1, $diff['fixed']);
    }

    public function test_restrict_recomputes_summary(): void
    {
        $newFinding = $this->finding(['category' => 'xss', 'severity' => 'high', 'title' => 'XSS', 'code_snippet' => 'echo $x;']);
        $results = [
            'all_findings' => [$this->finding(), $newFinding],
            'agent_results' => [
                'security' => ['findings' => [$this->finding(), $newFinding]],
            ],
            'summary' => ['total_issues' => 2, 'critical' => 1, 'high' => 1, 'medium' => 0, 'low' => 0],
        ];

        $restricted = Baseline::restrict($results, [$newFinding]);

        $this->assertCount(1, $restricted['all_findings']);
        $this->assertSame(1, $restricted['summary']['total_issues']);
        $this->assertSame(0, $restricted['summary']['critical']);
        $this->assertSame(1, $restricted['summary']['high']);
        $this->assertCount(1, $restricted['agent_results']['security']['findings']);
    }
}
