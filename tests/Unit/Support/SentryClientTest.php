<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\SentryClient;
use PHPUnit\Framework\TestCase;

class SentryClientTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cg_sentry_' . uniqid();
        mkdir($this->root . '/app/Http/Controllers', 0775, true);
        file_put_contents($this->root . '/app/Http/Controllers/OrderController.php', "<?php\nclass OrderController {}\n");
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->root);
        parent::tearDown();
    }

    private function event(): array
    {
        return [
            'culprit' => 'App\\Http\\Controllers\\OrderController::show',
            'entries' => [
                ['type' => 'breadcrumbs', 'data' => []],
                ['type' => 'exception', 'data' => ['values' => [[
                    'type'  => 'TypeError',
                    'value' => 'Argument #1 ($id) must be of type int, string given',
                    'stacktrace' => ['frames' => [
                        ['filename' => 'vendor/laravel/framework/src/Router.php', 'function' => 'dispatch', 'lineNo' => 700, 'in_app' => false],
                        ['filename' => '/var/www/html/app/Http/Controllers/OrderController.php', 'function' => 'show', 'lineNo' => 42, 'in_app' => true,
                         'context' => [[40, 'public function show($id) {'], [42, '    return Order::find($id);']]],
                    ]],
                ]]]],
            ],
        ];
    }

    public function test_exception_of_reads_type_and_message(): void
    {
        $ex = SentryClient::exceptionOf($this->event());
        $this->assertSame('TypeError', $ex['type']);
        $this->assertStringContainsString('must be of type int', $ex['value']);
    }

    public function test_culprit_frame_picks_last_in_app_frame(): void
    {
        $frame = SentryClient::culpritFrame($this->event());
        $this->assertNotNull($frame);
        $this->assertStringContainsString('OrderController.php', $frame['filename']);
        $this->assertSame(42, $frame['lineno']);
        $this->assertSame('show', $frame['function']);
        $this->assertNotEmpty($frame['context']);
    }

    public function test_culprit_frame_falls_back_to_last_frame_when_no_in_app(): void
    {
        $event = ['exception' => ['values' => [[
            'type' => 'Error',
            'stacktrace' => ['frames' => [
                ['filename' => 'a.php', 'lineno' => 1, 'in_app' => false],
                ['filename' => 'b.php', 'lineno' => 2, 'in_app' => false],
            ]],
        ]]]];

        $frame = SentryClient::culpritFrame($event);
        $this->assertSame('b.php', $frame['filename']);
        $this->assertSame(2, $frame['lineno']);
    }

    public function test_resolve_local_path_anchors_container_path_to_repo(): void
    {
        $rel = SentryClient::resolveLocalPath('/var/www/html/app/Http/Controllers/OrderController.php', $this->root);
        $this->assertSame('app/Http/Controllers/OrderController.php', $rel);
    }

    public function test_resolve_local_path_returns_null_when_missing(): void
    {
        $rel = SentryClient::resolveLocalPath('/srv/app/Http/Controllers/GhostController.php', $this->root);
        $this->assertNull($rel);
    }

    public function test_resolve_local_path_accepts_relative_path(): void
    {
        $rel = SentryClient::resolveLocalPath('app/Http/Controllers/OrderController.php', $this->root);
        $this->assertSame('app/Http/Controllers/OrderController.php', $rel);
    }

    public function test_summarise_issue_extracts_fields(): void
    {
        $s = SentryClient::summariseIssue([
            'id' => '123', 'title' => 'TypeError: boom', 'culprit' => 'X::y',
            'count' => '17', 'level' => 'error', 'permalink' => 'https://sentry.io/x/1/',
        ]);
        $this->assertSame('123', $s['id']);
        $this->assertSame(17, $s['count']);
        $this->assertSame('https://sentry.io/x/1/', $s['permalink']);
    }

    private function rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
