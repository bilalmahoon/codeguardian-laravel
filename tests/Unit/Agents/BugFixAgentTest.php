<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Agents;

use CodeGuardian\Laravel\Agents\BugFixAgent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BugFixAgentTest extends TestCase
{
    /** Build without the constructor so we don't touch AiClient/config(). */
    private function agent(): BugFixAgent
    {
        return (new ReflectionClass(BugFixAgent::class))->newInstanceWithoutConstructor();
    }

    private function call(BugFixAgent $agent, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($agent, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($agent, $args);
    }

    public function test_name_is_bugfix(): void
    {
        $this->assertSame('bugfix', $this->agent()->getName());
    }

    public function test_system_prompt_is_surgical_and_json_only(): void
    {
        $prompt = $this->call($this->agent(), 'getSystemPrompt');

        $this->assertStringContainsString('SURGICAL BUG FIX', $prompt);
        $this->assertStringContainsString('ROOT CAUSE', $prompt);
        $this->assertStringContainsString('"fixed_file"', $prompt);
        $this->assertStringContainsString('"cannot_fix"', $prompt);
        // Must instruct against broad refactoring.
        $this->assertStringContainsString('NOT A REFACTOR', $prompt);
    }

    public function test_user_prompt_embeds_exception_and_file(): void
    {
        $prompt = $this->call($this->agent(), 'buildUserPrompt', [[
            'file_path'    => 'app/Http/Controllers/OrderController.php',
            'file_content' => "<?php\nclass OrderController { public function show(\$id) {} }",
            'error_type'   => 'TypeError',
            'error_value'  => 'Argument #1 ($id) must be of type int, string given',
            'line'         => 42,
            'function'     => 'show',
            'culprit'      => 'App\\Http\\Controllers\\OrderController::show',
            'stack_trace'  => '   42 |     return Order::find($id);',
            'event_count'  => 12,
        ]]);

        $this->assertStringContainsString('TypeError', $prompt);
        $this->assertStringContainsString('must be of type int', $prompt);
        $this->assertStringContainsString('app/Http/Controllers/OrderController.php', $prompt);
        $this->assertStringContainsString('around line 42', $prompt);
        $this->assertStringContainsString('12 time(s)', $prompt);
        $this->assertStringContainsString('return Order::find', $prompt);
    }
}
