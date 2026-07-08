<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\SentryStateStore;
use PHPUnit\Framework\TestCase;

class SentryStateStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/cg_sentry_state_' . uniqid() . '/state.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));
        parent::tearDown();
    }

    public function test_unknown_issue_is_not_processed(): void
    {
        $store = new SentryStateStore($this->path);
        $this->assertFalse($store->isProcessed('ISSUE-1'));
        $this->assertNull($store->statusOf('ISSUE-1'));
    }

    public function test_marking_processed_persists_across_instances(): void
    {
        (new SentryStateStore($this->path))->markProcessed('ISSUE-1', 'fixed');

        $fresh = new SentryStateStore($this->path);
        $this->assertTrue($fresh->isProcessed('ISSUE-1'));
        $this->assertSame('fixed', $fresh->statusOf('ISSUE-1'));
    }

    public function test_forget_allows_reprocessing(): void
    {
        $store = new SentryStateStore($this->path);
        $store->markProcessed('ISSUE-1', 'analyzed');
        $store->forget('ISSUE-1');

        $this->assertFalse($store->isProcessed('ISSUE-1'));
    }

    public function test_corrupt_state_file_is_ignored(): void
    {
        @mkdir(dirname($this->path), 0775, true);
        file_put_contents($this->path, 'not json {{{');

        $store = new SentryStateStore($this->path);
        $this->assertFalse($store->isProcessed('ISSUE-1'));
    }
}
