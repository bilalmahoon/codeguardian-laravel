<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

/**
 * Ingests JSON output from other static analyzers (PHPStan, Psalm) and converts
 * it into CodeGuardian's finding shape so everything lands in one report.
 * Pure + testable — the command layer only handles file IO.
 */
final class ExternalFindings
{
    /**
     * Auto-detect the format from JSON content and convert to findings.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fromJson(string $json, string $projectRoot = ''): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return [];
        }

        // PHPStan: { files: { "<path>": { messages: [ { message, line } ] } } }
        if (isset($data['files']) && is_array($data['files'])) {
            return self::fromPhpstan($data, $projectRoot);
        }

        // Psalm: [ { type, message, file_name, line_from, severity } ]
        if (array_is_list($data)) {
            return self::fromPsalm($data, $projectRoot);
        }

        return [];
    }

    /** @return array<int,array<string,mixed>> */
    public static function fromPhpstan(array $data, string $projectRoot = ''): array
    {
        $findings = [];
        foreach (($data['files'] ?? []) as $file => $info) {
            foreach (($info['messages'] ?? []) as $msg) {
                $findings[] = self::make(
                    'phpstan',
                    'PHPStan: ' . self::headline((string) ($msg['message'] ?? 'Issue')),
                    (string) ($msg['message'] ?? ''),
                    self::relative((string) $file, $projectRoot),
                    (int) ($msg['line'] ?? 0),
                    ($msg['ignorable'] ?? true) ? 'medium' : 'high'
                );
            }
        }
        return $findings;
    }

    /** @return array<int,array<string,mixed>> */
    public static function fromPsalm(array $items, string $projectRoot = ''): array
    {
        $findings = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $sev = strtolower((string) ($item['severity'] ?? 'medium'));
            $findings[] = self::make(
                'psalm',
                'Psalm: ' . self::headline((string) ($item['message'] ?? $item['type'] ?? 'Issue')),
                (string) ($item['message'] ?? ''),
                self::relative((string) ($item['file_name'] ?? $item['file_path'] ?? ''), $projectRoot),
                (int) ($item['line_from'] ?? $item['line'] ?? 0),
                $sev === 'error' ? 'high' : ($sev === 'info' ? 'low' : 'medium')
            );
        }
        return $findings;
    }

    /** @return array<string,mixed> */
    private static function make(string $category, string $title, string $desc, string $file, int $line, string $severity): array
    {
        return [
            'severity'       => $severity,
            'category'       => $category,
            'title'          => $title,
            'description'    => $desc,
            'file'           => $file,
            'line_start'     => max(0, $line),
            'recommendation' => null,
            'confidence'     => 'high',
            'source'         => $category,
        ];
    }

    private static function headline(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        return mb_strlen($message) > 90 ? mb_substr($message, 0, 87) . '…' : $message;
    }

    private static function relative(string $file, string $projectRoot): string
    {
        $file = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
        if ($root !== '' && str_starts_with($file, $root . '/')) {
            return ltrim(substr($file, strlen($root)), '/');
        }
        return $file;
    }
}
