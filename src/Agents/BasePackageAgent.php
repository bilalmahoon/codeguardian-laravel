<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Agents;

use CodeGuardian\Laravel\Support\AiClient;

abstract class BasePackageAgent
{
    protected AiClient $ai;

    public function __construct()
    {
        $this->ai = new AiClient();
    }

    abstract public function getName(): string;

    abstract protected function getSystemPrompt(): string;

    abstract protected function buildUserPrompt(array $context): string;

    /**
     * Run analysis and return structured findings.
     */
    public function analyze(array $context): array
    {
        $userPrompt = $this->buildUserPrompt($context);
        $response   = $this->ai->complete($this->getSystemPrompt(), $userPrompt);
        $parsed     = AiClient::extractJson($response);

        if ($parsed === null) {
            return [
                'agent'    => $this->getName(),
                'error'    => 'Could not parse AI response as JSON',
                'raw'      => substr($response, 0, 500),
                'findings' => [],
            ];
        }

        return array_merge(['agent' => $this->getName()], $parsed);
    }

    /**
     * Truncate files in context to stay within token limits.
     */
    protected function prepareFiles(array $files, int $maxChars = 80_000): array
    {
        $result = [];
        $total  = 0;

        foreach ($files as $path => $content) {
            if ($total >= $maxChars) {
                break;
            }
            $remaining = $maxChars - $total;
            $snippet   = strlen($content) > $remaining
                ? substr($content, 0, $remaining) . "\n// ... [truncated]"
                : $content;

            $result[$path] = $snippet;
            $total        += strlen($snippet);
        }

        return $result;
    }

    /**
     * Format files as a readable block for the AI prompt.
     */
    protected function formatFilesForPrompt(array $files): string
    {
        $output = '';
        foreach ($files as $path => $content) {
            $output .= "\n\n### FILE: {$path}\n```\n{$content}\n```";
        }
        return $output;
    }

    protected function loadPrompt(string $name): string
    {
        $path = __DIR__ . '/../../resources/prompts/' . $name . '.md';
        return file_exists($path) ? file_get_contents($path) : '';
    }
}
