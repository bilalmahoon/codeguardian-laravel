<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Commands;

use CodeGuardian\Laravel\Support\PrComment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * `codeguardian:comment` — post (or update) a CodeGuardian summary as a comment
 * on the current GitHub PR or GitLab MR.
 *
 * Platform auto-detected from CI env vars (GITHUB_ACTIONS / GITLAB_CI) or forced
 * with --platform. GitHub uses the `gh` CLI; GitLab uses the REST API. Use
 * --dry-run to print the comment body without posting (safe everywhere).
 */
class CommentCommand extends Command
{
    protected $signature = 'codeguardian:comment
                            {--report=    : Path to a JSON report (default: latest in the report dir)}
                            {--platform=  : github | gitlab (auto-detected from CI env if omitted)}
                            {--mr=        : GitLab merge-request IID (default: from CI env)}
                            {--pr=        : GitHub PR number (default: gh resolves from branch)}
                            {--max=20     : Max findings to include in the comment}
                            {--dry-run    : Print the comment body; do not post}';

    protected $description = 'Post a CodeGuardian summary comment on the current PR / MR';

    public function handle(): int
    {
        $report = $this->loadReport();
        if ($report === null) {
            $this->error('No JSON report found. Run `codeguardian:analyze --format=json` first, or pass --report=.');
            return self::FAILURE;
        }

        $body = PrComment::body($report, (int) $this->option('max'));

        if ($this->option('dry-run')) {
            foreach (explode("\n", $body) as $line) {
                $this->line($line);
            }
            return self::SUCCESS;
        }

        $platform = $this->resolvePlatform();
        return match ($platform) {
            'github' => $this->postGitHub($body),
            'gitlab' => $this->postGitLab($body),
            default  => $this->failNoPlatform($body),
        };
    }

    /** @return array<string,mixed>|null */
    private function loadReport(): ?array
    {
        $path = $this->option('report');
        if (! is_string($path) || $path === '') {
            $path = $this->latestJsonReport();
        }

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function latestJsonReport(): ?string
    {
        $dir = storage_path((string) config('codeguardian.output.report_dir', 'codeguardian/reports'));
        $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        return $files[0];
    }

    private function resolvePlatform(): string
    {
        $forced = strtolower((string) ($this->option('platform') ?: ''));
        if (in_array($forced, ['github', 'gitlab'], true)) {
            return $forced;
        }
        if (getenv('GITHUB_ACTIONS') === 'true') {
            return 'github';
        }
        if (getenv('GITLAB_CI') === 'true') {
            return 'gitlab';
        }
        return '';
    }

    private function postGitHub(string $body): int
    {
        $args = ['gh', 'pr', 'comment'];
        if ($pr = (string) ($this->option('pr') ?: '')) {
            $args[] = $pr;
        }
        $args[] = '--body-file';
        $args[] = '-';

        $process = new Process($args);
        $process->setInput($body);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('gh failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
            $this->line('Ensure the GitHub CLI (gh) is installed and authenticated (GH_TOKEN).');
            return self::FAILURE;
        }

        $this->info('✓ Posted CodeGuardian comment to the PR.');
        return self::SUCCESS;
    }

    private function postGitLab(string $body): int
    {
        $token   = getenv('CODEGUARDIAN_GITLAB_TOKEN') ?: getenv('GITLAB_TOKEN') ?: getenv('CI_JOB_TOKEN');
        $apiBase = rtrim((string) (getenv('CI_API_V4_URL') ?: 'https://gitlab.com/api/v4'), '/');
        $project = getenv('CI_PROJECT_ID');
        $mr      = (string) ($this->option('mr') ?: getenv('CI_MERGE_REQUEST_IID') ?: '');

        if (! $token || ! $project || $mr === '') {
            $this->error('Missing GitLab context (token, CI_PROJECT_ID, or MR IID).');
            $this->line('Set CODEGUARDIAN_GITLAB_TOKEN and run inside a merge-request pipeline, or pass --mr=.');
            return self::FAILURE;
        }

        $url = "{$apiBase}/projects/{$project}/merge_requests/{$mr}/notes";
        $response = Http::withHeaders(['PRIVATE-TOKEN' => $token])
            ->asForm()
            ->post($url, ['body' => $body]);

        if (! $response->successful()) {
            $this->error("GitLab API failed ({$response->status()}): " . $response->body());
            return self::FAILURE;
        }

        $this->info('✓ Posted CodeGuardian comment to the merge request.');
        return self::SUCCESS;
    }

    private function failNoPlatform(string $body): int
    {
        $this->error('Could not detect the CI platform. Use --platform=github|gitlab, or --dry-run.');
        $this->newLine();
        $this->line($body);
        return self::FAILURE;
    }
}
