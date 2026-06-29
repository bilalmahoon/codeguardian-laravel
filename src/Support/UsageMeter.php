<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Tracks AI token usage (and an estimated cost) across a run. AiClient records
 * each response here; commands read the totals to show "what this cost".
 *
 * Parsing + cost maths are pure and unit-testable; the accumulator is a static
 * per-process store (PHP is single-threaded per request/command).
 */
final class UsageMeter
{
    /** @var array<int,array{provider:string,model:string,input:int,output:int}> */
    private static array $records = [];

    /**
     * Indicative USD price per 1,000,000 tokens [input, output]. Best-effort
     * defaults; override via config('codeguardian.pricing'). Unknown models cost 0.
     *
     * @var array<string,array{0:float,1:float}>
     */
    public const DEFAULT_PRICING = [
        // Anthropic
        'claude-opus'         => [15.0, 75.0],
        'claude-sonnet'       => [3.0, 15.0],
        'claude-haiku'        => [0.80, 4.0],
        'claude-3-5-sonnet'   => [3.0, 15.0],
        'claude-3-7-sonnet'   => [3.0, 15.0],
        // OpenAI
        'gpt-4o'              => [2.5, 10.0],
        'gpt-4o-mini'         => [0.15, 0.60],
        'gpt-4-turbo'         => [10.0, 30.0],
        // Google
        'gemini-1.5-pro'      => [1.25, 5.0],
        'gemini-1.5-flash'    => [0.075, 0.30],
        'gemini-2.0-flash'    => [0.10, 0.40],
    ];

    public static function reset(): void
    {
        self::$records = [];
    }

    public static function record(string $provider, string $model, int $inputTokens, int $outputTokens): void
    {
        if ($inputTokens <= 0 && $outputTokens <= 0) {
            return;
        }
        self::$records[] = [
            'provider' => $provider,
            'model'    => $model,
            'input'    => max(0, $inputTokens),
            'output'   => max(0, $outputTokens),
        ];
    }

    /**
     * Parse a raw provider response payload and record its usage.
     *
     * @param array<string,mixed> $data
     */
    public static function fromResponse(array $data, string $provider, string $model): void
    {
        [$in, $out] = self::extractTokens($data, $provider);
        self::record($provider, $model, $in, $out);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{0:int,1:int} [input, output]
     */
    public static function extractTokens(array $data, string $provider): array
    {
        return match ($provider) {
            'claude' => [
                (int) ($data['usage']['input_tokens'] ?? 0),
                (int) ($data['usage']['output_tokens'] ?? 0),
            ],
            'gemini' => [
                (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
                (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0),
            ],
            default => [
                (int) ($data['usage']['prompt_tokens'] ?? 0),
                (int) ($data['usage']['completion_tokens'] ?? 0),
            ],
        };
    }

    /**
     * Aggregate totals across all recorded calls.
     *
     * @param array<string,array{0:float,1:float}>|null $pricing
     * @return array{calls:int,input:int,output:int,total:int,cost_usd:float,by_model:array<string,array{calls:int,input:int,output:int,cost_usd:float}>}
     */
    public static function totals(?array $pricing = null): array
    {
        $pricing ??= self::pricing();

        $calls = 0;
        $input = 0;
        $output = 0;
        $cost  = 0.0;
        $byModel = [];

        foreach (self::$records as $r) {
            $calls++;
            $input  += $r['input'];
            $output += $r['output'];
            $rowCost = self::costFor($r['model'], $r['input'], $r['output'], $pricing);
            $cost   += $rowCost;

            $key = $r['model'];
            $byModel[$key]['calls']    = ($byModel[$key]['calls'] ?? 0) + 1;
            $byModel[$key]['input']    = ($byModel[$key]['input'] ?? 0) + $r['input'];
            $byModel[$key]['output']   = ($byModel[$key]['output'] ?? 0) + $r['output'];
            $byModel[$key]['cost_usd'] = round(($byModel[$key]['cost_usd'] ?? 0.0) + $rowCost, 4);
        }

        return [
            'calls'    => $calls,
            'input'    => $input,
            'output'   => $output,
            'total'    => $input + $output,
            'cost_usd' => round($cost, 4),
            'by_model' => $byModel,
        ];
    }

    /** @param array<string,array{0:float,1:float}> $pricing */
    public static function costFor(string $model, int $input, int $output, array $pricing): float
    {
        $rate = self::rateFor($model, $pricing);
        if ($rate === null) {
            return 0.0;
        }
        return round(($input / 1_000_000 * $rate[0]) + ($output / 1_000_000 * $rate[1]), 6);
    }

    /**
     * @param array<string,array{0:float,1:float}> $pricing
     * @return array{0:float,1:float}|null
     */
    private static function rateFor(string $model, array $pricing): ?array
    {
        $model = strtolower($model);
        if (isset($pricing[$model])) {
            return $pricing[$model];
        }
        // Longest matching key wins (e.g. 'claude-3-5-sonnet' before 'claude-sonnet').
        $best = null;
        $bestLen = 0;
        foreach ($pricing as $key => $rate) {
            $k = strtolower((string) $key);
            if (str_contains($model, $k) && strlen($k) > $bestLen) {
                $best = $rate;
                $bestLen = strlen($k);
            }
        }
        return $best;
    }

    /** @return array<string,array{0:float,1:float}> */
    private static function pricing(): array
    {
        try {
            $custom = config('codeguardian.pricing');
            if (is_array($custom) && $custom !== []) {
                return $custom + self::DEFAULT_PRICING;
            }
        } catch (\Throwable) {
            // no app container (unit tests) — fall through to defaults
        }
        return self::DEFAULT_PRICING;
    }
}
