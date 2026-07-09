<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\SlackService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class SlackServiceTest extends TestCase
{
    public function test_normalise_keeps_real_messages_and_drops_noise(): void
    {
        $raw = [
            ['type' => 'message', 'subtype' => 'channel_join', 'user' => 'U1', 'text' => 'joined', 'ts' => '1700000000.1'],
            ['type' => 'message', 'user' => 'U2', 'text' => 'DB is down!', 'ts' => '1700000100.2'],
            ['type' => 'message', 'text' => '', 'ts' => '1700000200.3'], // empty, no attachments → dropped
        ];

        $out = SlackService::normaliseMessages($raw);

        $this->assertCount(1, $out);
        $this->assertSame('U2', $out[0]['user']);
        $this->assertSame('DB is down!', $out[0]['text']);
        $this->assertNotSame('', $out[0]['time']);
    }

    public function test_configured_requires_token_and_channels(): void
    {
        $this->assertFalse((new SlackService('', []))->configured());
        $this->assertFalse((new SlackService('xoxb', []))->configured());
        $this->assertTrue((new SlackService('xoxb', [['id' => 'C1', 'label' => 'alerts']]))->configured());
    }

    public function test_channel_label_lookup(): void
    {
        $svc = new SlackService('xoxb', [['id' => 'C1', 'label' => 'alerts']]);
        $this->assertSame('alerts', $svc->channelLabel('C1'));
        $this->assertSame('C9', $svc->channelLabel('C9')); // unknown → id
        $this->assertSame('C1', $svc->defaultChannel());
    }

    public function test_messages_parses_slack_api_response(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'ok' => true,
                'messages' => [
                    ['type' => 'message', 'user' => 'U2', 'text' => 'Deploy failed', 'ts' => '1700000100.2'],
                ],
            ])),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $svc  = new SlackService('xoxb', [['id' => 'C1', 'label' => 'alerts']], $http);

        $msgs = $svc->messages('C1', 10);
        $this->assertCount(1, $msgs);
        $this->assertSame('Deploy failed', $msgs[0]['text']);
    }

    public function test_messages_empty_on_api_error(): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode(['ok' => false, 'error' => 'not_in_channel']))]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $svc  = new SlackService('xoxb', [['id' => 'C1', 'label' => 'alerts']], $http);

        $this->assertSame([], $svc->messages('C1'));
    }
}
