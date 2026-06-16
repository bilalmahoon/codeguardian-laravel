<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Support\ReportFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ReportCommand extends Command
{
    protected $signature = 'codeguardian:report
                            {--last    : Regenerate report from the most recent scan result}
                            {--file=   : Path to a specific scan JSON file}
                            {--format= : Output format: html, json, or both (default: html)}
                            {--open    : Open the HTML report in the browser after generation}';

    protected $description = 'Generate or re-generate an HTML/JSON report from a previous scan';

    public function handle(ReportFormatter $formatter): int
    {
        $format = $this->option('format') ?: 'html';
        $file   = $this->option('file');

        if ($this->option('last') || ! $file) {
            $file = $this->findLastReport();
        }

        if (! $file || ! file_exists($file)) {
            $this->error('No scan result found. Run `php artisan codeguardian:analyze` first.');
            return self::FAILURE;
        }

        $this->info("📊 Generating report from: {$file}");

        $results = json_decode(file_get_contents($file), true);

        if (! $results) {
            $this->error('Could not parse scan result file.');
            return self::FAILURE;
        }

        $outputDir = dirname($file);
        $paths     = $formatter->save($results, $outputDir, $format);

        $this->newLine();
        $this->info('Reports generated:');
        foreach ($paths as $p) {
            $this->line("  → {$p}");
        }

        if ($this->option('open')) {
            $htmlPath = collect($paths)->first(fn($p) => str_ends_with($p, '.html'));
            if ($htmlPath) {
                $this->line("Opening: {$htmlPath}");
                exec('open "' . $htmlPath . '" 2>/dev/null || xdg-open "' . $htmlPath . '" 2>/dev/null || start "' . $htmlPath . '"');
            }
        }

        return self::SUCCESS;
    }

    private function findLastReport(): ?string
    {
        $dir = storage_path(config('codeguardian.output.report_dir', 'codeguardian/reports'));

        if (! is_dir($dir)) {
            return null;
        }

        $files = glob($dir . '/scan-*.json');
        if (empty($files)) {
            return null;
        }

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        return $files[0];
    }
}
