<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Http\Controllers\Integrations;

use CodeGuardian\Laravel\Support\AiClient;
use CodeGuardian\Laravel\Support\RunStore;
use CodeGuardian\Laravel\Support\SentryClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sentry panel — browse production exceptions with rich filters and drill into
 * any issue's full detail (exception, stack trace, and the exact local file).
 *
 * Pure presentation + orchestration: all Sentry knowledge lives in
 * {@see SentryClient}. Future actions (resolve/ignore/assign/comment) slot in as
 * new methods + routes without touching this listing.
 */
class SentryController
{
    public function __construct(
        private readonly SentryClient $sentry,
        private readonly RunStore $runs,
    ) {
    }

    public function index(Request $request): Response
    {
        if (! $this->sentry->configured()) {
            return $this->setup();
        }

        $filters = [
            'status'      => (string) $request->query('status', 'unresolved'),
            'level'       => (string) $request->query('level', ''),
            'environment' => (string) $request->query('environment', ''),
            'period'      => (string) $request->query('period', '14d'),
            'project'     => (string) $request->query('project', ''),
        ];

        $issues = array_map(
            fn(array $i) => SentryClient::panelSummary($i),
            $this->sentry->listIssues($filters, 50)
        );

        return response()->view('codeguardian::integrations.sentry.index', [
            'configured'   => true,
            'issues'       => $issues,
            'filters'      => $filters,
            'statuses'     => SentryClient::STATUSES,
            'levels'       => SentryClient::LEVELS,
            'periods'      => SentryClient::PERIODS,
            'projects'     => $this->sentry->projects(),
            'environments' => $this->sentry->environments(),
            'currentProject' => $filters['project'] ?: $this->sentry->defaultProject(),
        ]);
    }

    public function show(string $id): Response
    {
        if (! $this->sentry->configured()) {
            return $this->setup();
        }

        $issue = $this->sentry->issue($id);
        if ($issue === null) {
            abort(404, 'Issue not found in Sentry.');
        }

        $event   = $this->sentry->latestEvent($id) ?? [];
        $summary = SentryClient::panelSummary($issue);
        $frame   = $event !== [] ? SentryClient::culpritFrame($event) : null;

        $localPath = null;
        if ($frame !== null && $frame['filename'] !== '') {
            $localPath = SentryClient::resolveLocalPath($frame['filename'], base_path());
        }

        return response()->view('codeguardian::integrations.sentry.show', [
            'configured' => true,
            'summary'    => $summary,
            'exception'  => $event !== [] ? SentryClient::exceptionOf($event) : ['type' => '', 'value' => ''],
            'frame'      => $frame,
            'localPath'  => $localPath,
        ]);
    }

    /**
     * Launch a safe, test-verified auto-fix for one issue as a background run,
     * then jump to its live output page. Mirrors the CLI:
     *   codeguardian:sentry --issue=ID --fix --apply --with-tests --resolve
     */
    public function fix(string $id): RedirectResponse
    {
        if (! $this->sentry->configured()) {
            return redirect()->route('codeguardian.sentry.index')
                ->with('cg_error', 'Sentry is not configured.');
        }

        if (! AiClient::hasApiKey()) {
            return redirect()->route('codeguardian.sentry.show', ['id' => $id])
                ->with('cg_error', 'Auto-fix needs an AI provider key (set CODEGUARDIAN_MODE=hybrid + your key).');
        }

        $runId = $this->runs->start('sentry', 'codeguardian:sentry', [
            'issue'      => $id,
            'fix'        => true,
            'apply'      => true,
            'with-tests' => true,
            'resolve'    => true,
        ], 'Sentry fix: ' . $id);

        return redirect()->route('codeguardian.show', ['id' => $runId])
            ->with('cg_status', 'Started a safe auto-fix for this Sentry issue. Watch it run below.');
    }

    private function setup(): Response
    {
        return response()->view('codeguardian::integrations.sentry.index', [
            'configured' => false,
            'missing'    => $this->sentry->missingConfig(),
            'issues'     => [],
            'filters'    => [],
        ]);
    }
}
