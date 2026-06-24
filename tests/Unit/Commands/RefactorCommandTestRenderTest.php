<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Commands;

use CodeGuardian\Laravel\Commands\RefactorCommand;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the AI "tests_needed" rendering.
 *
 * A real production bug: Claude returns tests_needed as an array of structured
 * objects ({scenario, type, priority, description}), but the command rendered
 * each with "{$test}" string interpolation → "Array to string conversion" →
 * the surrounding try/catch swallowed it and File::put never ran, silently
 * discarding EVERY AI refactor. stringifyTest() makes rendering total.
 */
class RefactorCommandTestRenderTest extends TestCase
{
    private function stringify(mixed $test): string
    {
        $cmd    = (new \ReflectionClass(RefactorCommand::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($cmd, 'stringifyTest');

        return $method->invoke($cmd, $test);
    }

    /** @test */
    public function test_structured_object_form_does_not_throw(): void
    {
        $out = $this->stringify([
            'scenario'    => 'login with valid credentials',
            'type'        => 'feature',
            'priority'    => 'high',
            'description' => 'returns a 200 with a token',
        ]);

        $this->assertStringContainsString('login with valid credentials', $out);
        $this->assertStringContainsString('returns a 200 with a token', $out);
    }

    /** @test */
    public function test_plain_string_form_is_preserved(): void
    {
        $this->assertSame(
            'it logs the user in',
            $this->stringify('it logs the user in')
        );
    }

    /** @test */
    public function test_scenario_only_object(): void
    {
        $this->assertSame(
            'token expiry handling',
            $this->stringify(['scenario' => 'token expiry handling'])
        );
    }

    /** @test */
    public function test_unknown_array_shape_falls_back_to_json(): void
    {
        // Must never throw, even for shapes we don't recognise.
        $out = $this->stringify(['foo' => 'bar', 'baz' => [1, 2, 3]]);
        $this->assertJson($out);
    }
}
