<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Support;

use CodeGuardian\Laravel\Support\UsageMeter;
use PHPUnit\Framework\TestCase;

class UsageMeterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        UsageMeter::reset();
    }

    public function test_extract_tokens_per_provider(): void
    {
        $this->assertSame([10, 20], UsageMeter::extractTokens(['usage' => ['input_tokens' => 10, 'output_tokens' => 20]], 'claude'));
        $this->assertSame([5, 7], UsageMeter::extractTokens(['usage' => ['prompt_tokens' => 5, 'completion_tokens' => 7]], 'openai'));
        $this->assertSame([3, 4], UsageMeter::extractTokens(['usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 4]], 'gemini'));
    }

    public function test_totals_accumulate_across_calls(): void
    {
        UsageMeter::record('claude', 'claude-opus-4-5', 1000, 500);
        UsageMeter::record('claude', 'claude-opus-4-5', 2000, 1000);

        $t = UsageMeter::totals();
        $this->assertSame(2, $t['calls']);
        $this->assertSame(3000, $t['input']);
        $this->assertSame(1500, $t['output']);
        $this->assertSame(4500, $t['total']);
        $this->assertArrayHasKey('claude-opus-4-5', $t['by_model']);
    }

    public function test_cost_estimation_uses_longest_matching_model_key(): void
    {
        $pricing = ['claude-sonnet' => [3.0, 15.0], 'claude-3-5-sonnet' => [3.0, 15.0]];
        // 1M input + 1M output at sonnet rates = 3 + 15 = 18
        $cost = UsageMeter::costFor('claude-3-5-sonnet-20241022', 1_000_000, 1_000_000, $pricing);
        $this->assertEqualsWithDelta(18.0, $cost, 0.0001);
    }

    public function test_unknown_model_costs_zero(): void
    {
        $this->assertSame(0.0, UsageMeter::costFor('mystery-model', 1000, 1000, ['gpt-4o' => [2.5, 10.0]]));
    }

    public function test_reset_clears_records(): void
    {
        UsageMeter::record('openai', 'gpt-4o', 10, 10);
        UsageMeter::reset();
        $this->assertSame(0, UsageMeter::totals()['calls']);
    }
}
