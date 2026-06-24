<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Http\Controllers;

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
     * Supported operations mapped to their artisan command and the options
     * each one accepts. Anything not listed here is rejected.
     *
     * @var array<string,array{artisan:string,label:string,options:array<int,string>}>
     */
    private const OPERATIONS = [
        'analyze' => [
            'artisan' => 'codeguardian:analyze',
            'label'   => 'Analyze',
            'options' => ['path', 'module', 'api', 'type', 'mode', 'format'],
        ],
        'refactor' => [
            'artisan' => 'codeguardian:refactor',
            'label'   => 'Refactor',
            'options' => ['path', 'module', 'api', 'file', 'type', 'mode', 'with-existing-tests'],
        ],
        'security' => [
            'artisan' => 'codeguardian:security',
            'label'   => 'Security audit',
            'options' => ['path', 'type', 'mode'],
        ],
        'performance' => [
            'artisan' => 'codeguardian:performance',
            'label'   => 'Performance review',
            'options' => ['path', 'type', 'mode'],
        ],
        'generate-tests' => [
            'artisan' => 'codeguardian:test',
            'label'   => 'Generate tests',
            'options' => ['path', 'file', 'type', 'mode'],
        ],
    ];

    public function __construct(private readonly RunStore $runs)
    {
    }

    public function index(): Response
    {
        return response()->view('codeguardian::index', [
            'runs'    => $this->runs->all(),
            'aiReady' => $this->aiStatus(),
        ]);
    }

    public function create(): Response
    {
        return response()->view('codeguardian::create', [
            'operations' => self::OPERATIONS,
            'aiReady'    => $this->aiStatus(),
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

        return response()->view('codeguardian::show', [
            'run'     => $run,
            'reports' => $this->runs->reportsFor($run),
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
