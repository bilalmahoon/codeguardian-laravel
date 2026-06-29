<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\HistoryStore;
use PHPUnit\Framework\TestCase;

class HistoryStoreTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/cg-history-' . uniqid() . '/history.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
            @rmdir(dirname($this->file));
        }
        parent::tearDown();
    }

    private function results(int $score, int $total): array
    {
        return [
            'project_name'  => 'demo',
            'overall_score' => $score,
            'grade'         => 'B',
            'summary'       => [
                'total_issues' => $total, 'critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4,
                'risk_score' => 40, 'risk_level' => 'medium',
            ],
            'quality' => ['dimensions' => [
                'security' => ['label' => 'Security', 'score' => 70],
            ]],
        ];
    }

    public function test_summary_from_extracts_metrics(): void
    {
        $rec = HistoryStore::summaryFrom($this->results(82, 10), ['scope' => 'project']);

        $this->assertSame('demo', $rec['project']);
        $this->assertSame(82, $rec['score']);
        $this->assertSame(10, $rec['total']);
        $this->assertSame(40, $rec['risk']);
        $this->assertSame('project', $rec['scope']);
        $this->assertSame(70, $rec['dimensions']['security']);
        $this->assertArrayHasKey('at', $rec);
    }

    public function test_record_and_recent_roundtrip(): void
    {
        $store = new HistoryStore($this->file);
        $this->assertTrue($store->record($this->results(70, 20)));
        $this->assertTrue($store->record($this->results(80, 12)));

        $recent = $store->recent(10);
        $this->assertCount(2, $recent);
        $this->assertSame(70, $recent[0]['score']);
        $this->assertSame(80, $recent[1]['score']);
    }

    public function test_recent_limits_and_orders_oldest_to_newest(): void
    {
        $store = new HistoryStore($this->file);
        foreach ([60, 65, 70, 75, 80] as $s) {
            $store->record($this->results($s, 5));
        }

        $recent = $store->recent(3);
        $this->assertCount(3, $recent);
        $this->assertSame([70, 75, 80], array_column($recent, 'score'));
    }

    public function test_recent_empty_when_no_file(): void
    {
        $store = new HistoryStore($this->file);
        $this->assertSame([], $store->recent());
    }
}
