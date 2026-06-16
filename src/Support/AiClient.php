<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class AiClient
{
    private Client $http;
    private string $provider;

    public function __construct()
    {
        $this->provider = config('codeguardian.provider', 'openai');
        $this->http     = new Client(['timeout' => 120]);
    }

    public function complete(string $systemPrompt, string $userPrompt): string
    {
        return match ($this->provider) {
            'claude' => $this->callClaude($systemPrompt, $userPrompt),
            'gemini' => $this->callGemini($systemPrompt, $userPrompt),
            default  => $this->callOpenAi($systemPrompt, $userPrompt),
        };
    }

    private function callOpenAi(string $system, string $user): string
    {
        $cfg = config('codeguardian.openai');
        $this->assertKey($cfg['key'] ?? null, 'OpenAI');

        try {
            $response = $this->http->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $cfg['key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'       => $cfg['model'] ?? 'gpt-4o',
                    'max_tokens'  => $cfg['max_tokens'] ?? 8192,
                    'temperature' => $cfg['temperature'] ?? 0.1,
                    'messages'    => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user',   'content' => $user],
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            return $data['choices'][0]['message']['content'] ?? '';
        } catch (GuzzleException $e) {
            throw new RuntimeException('OpenAI request failed: ' . $e->getMessage());
        }
    }

    private function callClaude(string $system, string $user): string
    {
        $cfg = config('codeguardian.claude');
        $this->assertKey($cfg['key'] ?? null, 'Anthropic (Claude)');

        try {
            $response = $this->http->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key'         => $cfg['key'],
                    'anthropic-version' => '2023-06-01',
                    'Content-Type'      => 'application/json',
                ],
                'json' => [
                    'model'      => $cfg['model'] ?? 'claude-3-5-sonnet-20241022',
                    'max_tokens' => $cfg['max_tokens'] ?? 8192,
                    'system'     => $system,
                    'messages'   => [
                        ['role' => 'user', 'content' => $user],
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            return $data['content'][0]['text'] ?? '';
        } catch (GuzzleException $e) {
            throw new RuntimeException('Claude request failed: ' . $e->getMessage());
        }
    }

    private function callGemini(string $system, string $user): string
    {
        $cfg = config('codeguardian.gemini');
        $this->assertKey($cfg['key'] ?? null, 'Gemini');

        $model = $cfg['model'] ?? 'gemini-1.5-pro';
        $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$cfg['key']}";

        try {
            $response = $this->http->post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => [
                    'contents'        => [
                        ['role' => 'user', 'parts' => [['text' => $system . "\n\n" . $user]]],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => $cfg['max_tokens'] ?? 8192,
                        'temperature'     => $cfg['temperature'] ?? 0.1,
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } catch (GuzzleException $e) {
            throw new RuntimeException('Gemini request failed: ' . $e->getMessage());
        }
    }

    private function assertKey(?string $key, string $provider): void
    {
        if (empty($key)) {
            throw new RuntimeException(
                "{$provider} API key is not configured. " .
                "Set CODEGUARDIAN_{$this->providerEnvKey()}_KEY in your .env file."
            );
        }
    }

    private function providerEnvKey(): string
    {
        return strtoupper(match ($this->provider) {
            'claude' => 'CLAUDE',
            'gemini' => 'GEMINI',
            default  => 'OPENAI',
        });
    }

    /**
     * Extract JSON object from a raw AI response (handles markdown code fences).
     */
    public static function extractJson(string $response): ?array
    {
        // Strip markdown code fences
        $cleaned = preg_replace('/```(?:json)?\s*/i', '', $response);
        $cleaned = preg_replace('/```\s*$/', '', $cleaned);

        // Find first { ... } block
        $start = strpos($cleaned, '{');
        $end   = strrpos($cleaned, '}');

        if ($start === false || $end === false) {
            return null;
        }

        $jsonStr = substr($cleaned, $start, $end - $start + 1);
        $decoded = json_decode($jsonStr, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
