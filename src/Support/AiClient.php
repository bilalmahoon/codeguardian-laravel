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

    /**
     * Returns true if any AI provider key is configured in the environment.
     *
     * Uses the same provider default as the constructor ('openai') so that
     * hasApiKey() and complete() agree on which key to check.
     */
    public static function hasApiKey(): bool
    {
        $provider = config('codeguardian.provider', 'openai');

        return match ($provider) {
            'claude'  => ! empty(config('codeguardian.claude.key')),
            'gemini'  => ! empty(config('codeguardian.gemini.key')),
            'openai'  => ! empty(config('codeguardian.openai.key')),
            default   => ! empty(config('codeguardian.claude.key'))
                      || ! empty(config('codeguardian.openai.key'))
                      || ! empty(config('codeguardian.gemini.key')),
        };
    }

    /**
     * Set to true by the last call when the model stopped because it hit the
     * output token limit (response is truncated / incomplete). Callers can read
     * this via wasTruncated() to produce a helpful error instead of a confusing
     * "could not parse JSON" message.
     */
    private bool $lastResponseTruncated = false;

    public function wasTruncated(): bool
    {
        return $this->lastResponseTruncated;
    }

    /**
     * @param int|null $maxTokens  Override the configured output token limit for
     *                             this single call (used by the refactor agent,
     *                             which produces large full-file rewrites).
     */
    public function complete(string $systemPrompt, string $userPrompt, ?int $maxTokens = null): string
    {
        $this->lastResponseTruncated = false;

        return match ($this->provider) {
            'claude' => $this->callClaude($systemPrompt, $userPrompt, $maxTokens),
            'gemini' => $this->callGemini($systemPrompt, $userPrompt, $maxTokens),
            default  => $this->callOpenAi($systemPrompt, $userPrompt, $maxTokens),
        };
    }

    private function callOpenAi(string $system, string $user, ?int $maxTokens = null): string
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
                    'max_tokens'  => $maxTokens ?? $cfg['max_tokens'] ?? 8192,
                    'temperature' => $cfg['temperature'] ?? 0.1,
                    'messages'    => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user',   'content' => $user],
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            // finish_reason "length" means the output was cut off at the token limit
            if (($data['choices'][0]['finish_reason'] ?? null) === 'length') {
                $this->lastResponseTruncated = true;
            }

            return $data['choices'][0]['message']['content'] ?? '';
        } catch (GuzzleException $e) {
            throw new RuntimeException('OpenAI request failed: ' . $e->getMessage());
        }
    }

    private function callClaude(string $system, string $user, ?int $maxTokens = null): string
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
                    'max_tokens' => $maxTokens ?? $cfg['max_tokens'] ?? 8192,
                    'system'     => $system,
                    'messages'   => [
                        ['role' => 'user', 'content' => $user],
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            // stop_reason "max_tokens" means the model ran out of output budget
            // and the response is truncated (incomplete JSON).
            if (($data['stop_reason'] ?? null) === 'max_tokens') {
                $this->lastResponseTruncated = true;
            }

            return $data['content'][0]['text'] ?? '';
        } catch (GuzzleException $e) {
            $msg = $e->getMessage();

            // Detect model-not-found (404) and give an actionable error message
            if (str_contains($msg, '404') && str_contains($msg, 'not_found_error')) {
                $model = $cfg['model'] ?? 'unknown';
                throw new RuntimeException(
                    "Claude model '{$model}' not found on your account. " .
                    "Update CODEGUARDIAN_CLAUDE_MODEL in your .env to a model available on your plan. " .
                    "Check available models at: https://docs.anthropic.com/en/docs/models-overview " .
                    "Example: CODEGUARDIAN_CLAUDE_MODEL=claude-opus-4-5"
                );
            }

            throw new RuntimeException('Claude request failed: ' . $msg);
        }
    }

    private function callGemini(string $system, string $user, ?int $maxTokens = null): string
    {
        $cfg = config('codeguardian.gemini');
        $this->assertKey($cfg['key'] ?? null, 'Gemini');

        $model = $cfg['model'] ?? 'gemini-1.5-flash';

        // Always use v1beta — it supports all models including stable ones
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$cfg['key']}";

        try {
            $response = $this->http->post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => [
                    'contents'        => [
                        ['role' => 'user', 'parts' => [['text' => $system . "\n\n" . $user]]],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => $maxTokens ?? $cfg['max_tokens'] ?? 8192,
                        'temperature'     => $cfg['temperature'] ?? 0.1,
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            // finishReason "MAX_TOKENS" means the output was cut off
            if (($data['candidates'][0]['finishReason'] ?? null) === 'MAX_TOKENS') {
                $this->lastResponseTruncated = true;
            }

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
     * Extract a JSON object from a raw AI response.
     *
     * Robust against the common ways LLM responses break naive json_decode:
     *   - Markdown ```json fences (with or without language tag)
     *   - Prose before/after the JSON object
     *   - Braces appearing inside string values (handled via string-aware scan)
     *   - A trailing truncated object (the scan finds the first COMPLETE object;
     *     if none completes, a best-effort repair closes open braces/strings)
     */
    public static function extractJson(string $response): ?array
    {
        if (trim($response) === '') {
            return null;
        }

        // Strip markdown code fences anywhere in the text
        $cleaned = preg_replace('/```(?:json)?/i', '', $response) ?? $response;

        $start = strpos($cleaned, '{');
        if ($start === false) {
            return null;
        }

        // 1) Try a string-aware balanced-brace scan to find the first complete object.
        $balanced = self::scanBalancedObject($cleaned, $start);
        if ($balanced !== null) {
            $decoded = json_decode($balanced, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // 2) Fallback: first "{" to last "}" (original behaviour).
        $end = strrpos($cleaned, '}');
        if ($end !== false && $end > $start) {
            $decoded = json_decode(substr($cleaned, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // 3) Last resort: best-effort repair of a truncated object.
        $repaired = self::repairTruncatedJson(substr($cleaned, $start));
        if ($repaired !== null) {
            $decoded = json_decode($repaired, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Scan from $start for the first balanced { ... } object, correctly skipping
     * braces that appear inside JSON string literals (respecting \" escapes).
     * Returns the substring, or null if no balanced object completes.
     */
    private static function scanBalancedObject(string $s, int $start): ?string
    {
        $depth     = 0;
        $inString  = false;
        $escaped   = false;
        $len       = strlen($s);

        for ($i = $start; $i < $len; $i++) {
            $ch = $s[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * Best-effort repair of a JSON object that was truncated mid-output:
     *   - closes an unterminated string
     *   - appends the right number of closing braces / brackets
     * Returns null if it does not look recoverable.
     */
    private static function repairTruncatedJson(string $s): ?string
    {
        $stack    = [];   // tracks the nesting order of '{' and '[' as they open
        $inString = false;
        $escaped  = false;
        $len      = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            switch ($ch) {
                case '"': $inString = true; break;
                case '{': $stack[] = '}'; break;
                case '[': $stack[] = ']'; break;
                case '}':
                case ']':
                    array_pop($stack);
                    break;
            }
        }

        if (empty($stack)) {
            return null; // nothing meaningful to repair
        }

        $repaired = rtrim($s);

        // Close an open string first
        if ($inString) {
            $repaired .= '"';
        }

        // Drop a dangling trailing comma (e.g. "...,") which is invalid JSON
        $repaired = preg_replace('/,\s*$/', '', $repaired);

        // Close open structures in the correct (reverse) order
        while (! empty($stack)) {
            $repaired .= array_pop($stack);
        }

        return $repaired;
    }
}
