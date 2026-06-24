<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\AiClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AiClient::extractJson — the parser that turns a raw LLM response
 * into a structured array. This is the function that previously failed with
 * "Could not parse AI response as JSON" on large refactoring outputs, silently
 * discarding the rewrite. These tests lock in the robustness fixes.
 */
class AiClientJsonTest extends TestCase
{
    /** @test */
    public function test_parses_plain_json(): void
    {
        $json   = '{"agent":"refactor","changes":[]}';
        $result = AiClient::extractJson($json);

        $this->assertIsArray($result);
        $this->assertSame('refactor', $result['agent']);
    }

    /** @test */
    public function test_parses_json_wrapped_in_markdown_fences(): void
    {
        $response = "Here is the result:\n```json\n{\"agent\":\"refactor\",\"ok\":true}\n```\nDone.";
        $result   = AiClient::extractJson($response);

        $this->assertIsArray($result);
        $this->assertTrue($result['ok']);
    }

    /** @test */
    public function test_parses_json_with_prose_before_and_after(): void
    {
        $response = "Sure! {\"value\":42} hope that helps";
        $result   = AiClient::extractJson($response);

        $this->assertSame(42, $result['value']);
    }

    /** @test */
    public function test_braces_inside_string_values_do_not_break_parsing(): void
    {
        // The "refactored_file" contains PHP code with many { } braces inside the string
        $php  = "<?php\nclass Foo {\n    public function bar() {\n        return ['a' => 1];\n    }\n}";
        $json = json_encode(['agent' => 'refactor', 'refactored_file' => $php]);

        $result = AiClient::extractJson("```json\n{$json}\n```");

        $this->assertIsArray($result);
        $this->assertSame($php, $result['refactored_file']);
    }

    /** @test */
    public function test_picks_first_complete_object_when_trailing_garbage_follows(): void
    {
        $response = '{"first":true}{"second":false}';
        $result   = AiClient::extractJson($response);

        // Balanced scan must return the FIRST complete object only
        $this->assertArrayHasKey('first', $result);
        $this->assertArrayNotHasKey('second', $result);
    }

    /** @test */
    public function test_repairs_truncated_object_with_open_braces(): void
    {
        // Simulate a response cut off at the token limit: open object + array, no closing
        $truncated = '{"agent":"refactor","changes":[{"type":"extract_service","description":"moved logic"';

        $result = AiClient::extractJson($truncated);

        $this->assertIsArray($result);
        $this->assertSame('refactor', $result['agent']);
        $this->assertSame('extract_service', $result['changes'][0]['type']);
    }

    /** @test */
    public function test_repairs_truncated_object_with_unterminated_string(): void
    {
        // Cut off in the middle of a string value
        $truncated = '{"agent":"refactor","summary":"this got cut off mid sente';

        $result = AiClient::extractJson($truncated);

        $this->assertIsArray($result);
        $this->assertSame('refactor', $result['agent']);
        $this->assertStringStartsWith('this got cut off', $result['summary']);
    }

    /** @test */
    public function test_returns_null_for_empty_response(): void
    {
        $this->assertNull(AiClient::extractJson(''));
        $this->assertNull(AiClient::extractJson('   '));
    }

    /** @test */
    public function test_returns_null_when_no_json_present(): void
    {
        $this->assertNull(AiClient::extractJson('I could not complete the request.'));
    }

    /** @test */
    public function test_handles_nested_objects_and_arrays(): void
    {
        $data = [
            'agent'           => 'refactor',
            'overall'         => ['performance' => 8, 'nested' => ['deep' => true]],
            'generated_files' => ['app/Services/Foo.php' => "<?php\nclass Foo {}"],
            'changes'         => [
                ['type' => 'a', 'meta' => ['x' => 1]],
                ['type' => 'b', 'meta' => ['y' => 2]],
            ],
        ];
        $json = json_encode($data);

        $result = AiClient::extractJson($json);

        $this->assertSame(8, $result['overall']['performance']);
        $this->assertTrue($result['overall']['nested']['deep']);
        $this->assertCount(2, $result['changes']);
    }
}
