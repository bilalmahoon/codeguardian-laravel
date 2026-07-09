<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Http\Controllers\Integrations;

use CodeGuardian\Laravel\Support\SentryClient;
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
    public function __construct(private readonly SentryClient $sentry)
    {
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
