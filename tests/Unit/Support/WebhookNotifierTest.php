<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\WebhookNotifier;
use PHPUnit\Framework\TestCase;

class WebhookNotifierTest extends TestCase
{
    private function results(): array
    {
        return [
            'overall_score' => 72,
            'grade'         => 'C',
            'summary'       => [
                'total_issues' => 9,
                'critical'     => 1,
                'high'         => 2,
                'medium'       => 3,
                'low'          => 3,
                'risk_score'   => 40,
            ],
        ];
    }

    public function test_slack_payload_has_text_and_blocks(): void
    {
        $payload = WebhookNotifier::slack($this->results(), 'demo');

        $this->assertArrayHasKey('text', $payload);
        $this->assertArrayHasKey('blocks', $payload);
        $this->assertStringContainsString('demo', $payload['text']);
        $this->assertStringContainsString('72/100', $payload['text']);
        $this->assertStringContainsString('🔴', $payload['text']); // critical present
    }

    public function test_teams_payload_is_message_card(): void
    {
        $payload = WebhookNotifier::teams($this->results(), 'demo');

        $this->assertSame('MessageCard', $payload['@type']);
        $this->assertArrayHasKey('themeColor', $payload);
        $this->assertSame('demo — CodeGuardian report', $payload['summary']);
    }

    public function test_generic_payload_shape(): void
    {
        $payload = WebhookNotifier::generic($this->results(), 'demo');

        $this->assertSame('demo', $payload['project']);
        $this->assertSame(72, $payload['quality']);
        $this->assertSame(40, $payload['risk']);
        $this->assertSame(1, $payload['issues']['critical']);
        $this->assertSame(9, $payload['issues']['total']);
    }

    public function test_build_dispatches_by_format(): void
    {
        $this->assertArrayHasKey('@type', WebhookNotifier::build('teams', $this->results()));
        $this->assertArrayHasKey('project', WebhookNotifier::build('generic', $this->results()));
        $this->assertArrayHasKey('blocks', WebhookNotifier::build('slack', $this->results()));
        $this->assertArrayHasKey('blocks', WebhookNotifier::build('unknown', $this->results())); // defaults to slack
    }

    public function test_clean_result_uses_green_emoji(): void
    {
        $clean = ['overall_score' => 100, 'grade' => 'A', 'summary' => ['total_issues' => 0]];
        $payload = WebhookNotifier::slack($clean);
        $this->assertStringContainsString('🟢', $payload['text']);
    }
}
