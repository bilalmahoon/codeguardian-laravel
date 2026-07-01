<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Http\Controllers;

use CodeGuardian\Laravel\Support\DashboardInsights;
use CodeGuardian\Laravel\Support\HistoryStore;
use CodeGuardian\Laravel\Support\ProjectMetadata;
use CodeGuardian\Laravel\Support\RunStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Web dashboard for CodeGuardian — run scans/refactors, watch live progress,
 * and browse the history of past runs with their results, without the terminal.
 */
class DashboardController
{
    /**
     * Supported operations mapped to their artisan command, the scalar options
     * each accepts (mode/format/…), and the TARGET TYPES the user may pick from
     * a dropdown. Anything not listed here is rejected.
     *
     * @var array<string,array{artisan:string,label:string,options:array<int,string>,targets:array<int,string>}>
     */
    private const OPERATIONS = [
        'analyze' => [
            'artisan' => 'codeguardian:analyze',
            'label'   => 'Analyze',
            'options' => ['mode', 'format'],
            'targets' => ['project', 'module', 'api', 'web'],
        ],
        'refactor' => [
            'artisan' => 'codeguardian:refactor',
            'label'   => 'Refactor',
            'options' => ['mode', 'with-existing-tests'],
            'targets' => ['project', 'module', 'api', 'web', 'file', 'command'],
        ],
        'security' => [
            'artisan' => 'codeguardian:security',
            'label'   => 'Security audit',
            'options' => ['mode'],
            'targets' => ['project', 'path'],
        ],
        'performance' => [
            'artisan' => 'codeguardian:performance',
            'label'   => 'Performance review',
            'options' => ['mode'],
            'targets' => ['project', 'path'],
        ],
        'generate-tests' => [
            'artisan' => 'codeguardian:test',
            'label'   => 'Generate tests',
            'options' => ['mode'],
            'targets' => ['project', 'file', 'command', 'path'],
        ],
    ];

    /**
     * How each target type maps to a CLI option. 'project' means no target
     * option (whole project). Both 'web' and 'api' resolve by route URI
     * (--api), and both 'command' and 'file' resolve to a file path (--file).
     *
     * @var array<string,?string>
     */
    private const TARGET_OPTION = [
        'project' => null,
        'module'  => 'module',
        'api'     => 'api',
        'web'     => 'api',
        'file'    => 'file',
        'command' => 'file',
        'path'    => 'path',
    ];

    public function __construct(private readonly RunStore $runs)
    {
    }

    public function index(): Response
    {
        $history = HistoryStore::fromConfig()->recent(30);

        return response()->view('codeguardian::index', [
            'runs'    => $this->runs->all(),
            'aiReady' => $this->aiStatus(),
            'trend'   => DashboardInsights::fromHistory($history),
        ]);
    }

    /**
     * Insights: code-health trends over time + the latest run's category,
     * severity, quality, and hotspot breakdown.
     */
    public function insights(): Response
    {
        $history = HistoryStore::fromConfig()->recent(60);
        $trend   = DashboardInsights::fromHistory($history);

        // Latest analyze run's JSON report powers the breakdown panels.
        $report = null;
        foreach ($this->runs->all() as $run) {
            if (($run['type'] ?? null) === 'analyze' && ($run['status'] ?? null) === 'completed') {
                $report = $this->runs->reportData($run);
                if ($report !== null) {
                    break;
                }
            }
        }

        $findings = is_array($report['all_findings'] ?? null) ? $report['all_findings'] : [];

        return response()->view('codeguardian::insights', [
            'aiReady'    => $this->aiStatus(),
            'trend'      => $trend,
            'scoreSpark' => DashboardInsights::sparkline(array_column($trend['points'], 'score'), 600, 120, '#3fb950'),
            'riskSpark'  => DashboardInsights::sparkline(array_column($trend['points'], 'risk'), 600, 120, '#f85149'),
            'categories' => DashboardInsights::categoryBreakdown($findings),
            'severity'   => DashboardInsights::severityBreakdown($findings),
            'report'     => $report,
            'hotspots'   => is_array($report['summary']['hotspot_files'] ?? null) ? $report['summary']['hotspot_files'] : [],
        ]);
    }

    public function create(): Response
    {
        $meta    = ProjectMetadata::forCurrentApp();
        $routes  = $meta->routes();

        return response()->view('codeguardian::create', [
            'operations'  => self::OPERATIONS,
            'aiReady'     => $this->aiStatus(),
            'modules'     => $meta->modules(),
            'apiRoutes'   => $routes['api'],
            'webRoutes'   => $routes['web'],
            'commands'    => $meta->commands(),
            'files'       => $meta->files(),
            'targetLabels'=> $this->targetLabels(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $operation = (string) $request->input('operation', '');
        if (! isset(self::OPERATIONS[$operation])) {
            return back()->withInput()->with('cg_error', 'Unknown operation.');
        }

        $spec    = self::OPERATIONS[$operation];
        $options = $this->collectOptions($request, $spec['options'], $operation);

        // Resolve the chosen target (dropdown) into the right CLI option.
        $targetType  = (string) $request->input('target_type', 'project');
        $targetValue = trim((string) $request->input('target_value', ''));

        if (! in_array($targetType, $spec['targets'], true)) {
            return back()->withInput()->with('cg_error', 'That target is not valid for this operation.');
        }

        $targetOption = self::TARGET_OPTION[$targetType] ?? null;
        if ($targetOption !== null) {
            if ($targetValue === '') {
                return back()->withInput()->with('cg_error', 'Please choose a ' . $targetType . ' target.');
            }

            // A command is picked by name; resolve it to the file that defines it.
            if ($targetType === 'command') {
                $file = ProjectMetadata::forCurrentApp()->commandFile($targetValue);
                if ($file === null) {
                    return back()->withInput()->with('cg_error', "Command '{$targetValue}' not found.");
                }
                $targetValue = $file;
            }

            $options[$targetOption] = $targetValue;
        }

        $label = $this->buildLabel($spec['label'], $options);

        $id = $this->runs->start($operation, $spec['artisan'], $options, $label);

        return redirect()->route('codeguardian.show', ['id' => $id]);
    }

    public function show(string $id): Response
    {
        $run = $this->runs->find($id);
        if ($run === null) {
            abort(404);
        }

        $report   = ($run['type'] ?? null) === 'analyze' ? $this->runs->reportData($run) : null;
        $findings = is_array($report['all_findings'] ?? null) ? $report['all_findings'] : [];

        return response()->view('codeguardian::show', [
            'run'        => $run,
            'reports'    => $this->runs->reportsFor($run),
            'files'      => ($run['type'] ?? null) === 'analyze' ? $this->runs->reportFiles($run) : [],
            'report'     => $report,
            'findings'   => $findings,
            'severity'   => DashboardInsights::severityBreakdown($findings),
            'categories' => DashboardInsights::categoryBreakdown($findings),
        ]);
    }

    /** Live polling endpoint: returns status + new log content from $offset. */
    public function status(Request $request, string $id): JsonResponse
    {
        $run = $this->runs->find($id);
        if ($run === null) {
            return response()->json(['error' => 'not found'], 404);
        }

        $offset = (int) $request->query('offset', '0');
        $tail   = $this->runs->logTail($id, $offset);

        return response()->json([
            'status'    => $run['status'],
            'exit_code' => $run['exit_code'],
            'finished'  => in_array($run['status'], ['completed', 'failed'], true),
            'chunk'     => $tail['content'],
            'offset'    => $tail['offset'],
            'reports'   => array_map(
                fn($r) => ['name' => $r['name'], 'ext' => $r['ext']],
                $this->runs->reportsFor($run)
            ),
        ]);
    }

    /** Serve a run's HTML report inline. */
    public function report(string $id): Response
    {
        $html = $this->runs->reportHtml($id);
        if ($html === null) {
            abort(404, 'No HTML report found for this run yet.');
        }

        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    /**
     * "Fix these issues" — start a foolproof refactor run for a completed analyze
     * run. Fixes the specific files the user selected, or (if none) the whole
     * analyzed scope (same module/api/file, or the whole project).
     */
    public function fix(Request $request, string $id): RedirectResponse
    {
        $run = $this->runs->find($id);
        if ($run === null) {
            abort(404);
        }

        if (($run['type'] ?? null) !== 'analyze') {
            return back()->with('cg_error', 'Only analyze runs can be auto-fixed.');
        }

        $options = ['mode' => 'auto', 'safe' => true];

        // Prefer explicitly selected files (selective fix).
        $selected = $this->safeFiles((array) $request->input('files', []));
        if ($selected !== []) {
            $options['files'] = implode(',', $selected);
            $label = 'Fix: ' . count($selected) . ' selected file(s)';
        } else {
            // Otherwise carry over the analysis scope (module/api/file).
            $sourceOptions = is_array($run['options'] ?? null) ? $run['options'] : [];
            foreach (['module', 'api', 'file'] as $scope) {
                if (! empty($sourceOptions[$scope]) && is_string($sourceOptions[$scope])) {
                    $options[$scope] = $sourceOptions[$scope];
                }
            }
            $label = $this->buildLabel('Fix', $options);
        }

        $spec  = self::OPERATIONS['refactor'];
        $newId = $this->runs->start('refactor', $spec['artisan'], $options, $label);

        return redirect()->route('codeguardian.show', ['id' => $newId])
            ->with('cg_status', 'Started fixing the issues found by the analysis.');
    }

    /**
     * Sanitise a list of selected file paths: relative only, no traversal.
     *
     * @param array<int,mixed> $files
     * @return array<int,string>
     */
    private function safeFiles(array $files): array
    {
        $clean = [];
        foreach ($files as $file) {
            if (! is_string($file)) {
                continue;
            }
            $path = ltrim(str_replace('\\', '/', trim($file)), '/');
            if ($path === '' || str_contains($path, '..')) {
                continue;
            }
            $clean[$path] = true;
        }

        return array_keys($clean);
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->runs->delete($id);

        return redirect()->route('codeguardian.index')->with('cg_status', 'Run deleted.');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<int,string> $allowed
     * @return array<string,string|bool>
     */
    private function collectOptions(Request $request, array $allowed, string $operation): array
    {
        $options = [];

        foreach ($allowed as $opt) {
            $value = $request->input($opt);

            if ($opt === 'with-existing-tests') {
                if ($request->boolean('with-existing-tests')) {
                    $options[$opt] = true;
                }
                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $options[$opt] = trim($value);
            }
        }

        // Refactor always runs non-interactively from the web, and uses the
        // foolproof safe loop (generate test → refactor → verify → auto-rollback).
        if ($operation === 'refactor') {
            $options['mode'] = 'auto';
            $options['safe'] = true;
        }

        return $options;
    }

    /** Human labels for each target type (used by the form dropdown). */
    private function targetLabels(): array
    {
        return [
            'project' => 'Whole project',
            'module'  => 'Module',
            'api'     => 'API route',
            'web'     => 'Web route',
            'file'    => 'File',
            'command' => 'Artisan command',
            'path'    => 'Directory path',
        ];
    }

    /** @param array<string,string|bool> $options */
    private function buildLabel(string $base, array $options): string
    {
        foreach (['api', 'module', 'file', 'path'] as $key) {
            if (! empty($options[$key]) && is_string($options[$key])) {
                return "{$base}: {$options[$key]}";
            }
        }

        return "{$base}: whole project";
    }

    /** @return array{enabled:bool,provider:string,model:string} */
    private function aiStatus(): array
    {
        $mode     = (string) config('codeguardian.mode', 'static');
        $provider = (string) config('codeguardian.provider', 'openai');
        $key      = config("codeguardian.{$provider}.key");

        return [
            'enabled'  => in_array($mode, ['ai', 'hybrid'], true) && ! empty($key),
            'provider' => $provider,
            'model'    => (string) config("codeguardian.{$provider}.model", ''),
        ];
    }
}
