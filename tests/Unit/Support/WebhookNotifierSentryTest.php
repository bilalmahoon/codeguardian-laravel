<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\WebhookNotifier;
use PHPUnit\Framework\TestCase;

class WebhookNotifierSentryTest extends TestCase
{
    private function items(): array
    {
        return [
            [
                'title' => 'TypeError: bad arg', 'file' => 'app/Http/Controllers/OrderController.php',
                'line' => 42, 'permalink' => 'https://sentry.io/x/1/', 'events' => 17,
                'status' => 'fixed', 'root_cause' => 'Passed a string where an int was required.',
                'tests' => 'passed',
            ],
            [
                'title' => 'QueryException: column not found', 'file' => 'app/Models/User.php',
                'line' => 10, 'permalink' => '', 'events' => 3,
                'status' => 'unresolvable', 'root_cause' => 'Missing migration for new column.', 'tests' => '',
            ],
        ];
    }

    public function test_summary_has_text_and_blocks_and_counts(): void
    {
        $payload = WebhookNotifier::sentrySummary($this->items(), 'shop');

        $this->assertArrayHasKey('text', $payload);
        $this->assertArrayHasKey('blocks', $payload);
        $this->assertStringContainsString('shop', $payload['text']);
        $this->assertStringContainsString('2 issues triaged', $payload['text']);
        $this->assertStringContainsString('Fixed: *1*', $payload['text']);
        $this->assertStringContainsString('Needs attention: *1*', $payload['text']);
    }

    public function test_fixed_item_links_permalink_and_shows_location(): void
    {
        $payload = WebhookNotifier::sentrySummary($this->items());
        $json    = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('https://sentry.io/x/1/', $json);
        $this->assertStringContainsString('OrderController.php:42', $json);
        $this->assertStringContainsString('Fixed', $json);
    }

    public function test_uses_wrench_emoji_when_something_fixed(): void
    {
        $payload = WebhookNotifier::sentrySummary($this->items());
        $this->assertStringContainsString('🛠️', $payload['text']);
    }

    public function test_empty_list_is_search_emoji_and_zero_counts(): void
    {
        $payload = WebhookNotifier::sentrySummary([], 'shop');
        $this->assertStringContainsString('0 issues triaged', $payload['text']);
        $this->assertStringContainsString('🔍', $payload['text']);
    }
}
