@extends('codeguardian::layout')

@section('title', 'CodeGuardian — New run')

@section('content')
    <h1>New run</h1>
    <p class="muted">Pick an operation and choose a target from the list. The run starts in the background and streams live progress.</p>

    @unless($aiReady['enabled'])
        <div class="banner warn">
            No AI provider configured — runs use the static engine only. For expert-level (Claude) analysis &
            refactoring, set <span class="mono">CODEGUARDIAN_MODE=hybrid</span> and your provider API key in <span class="mono">.env</span>.
        </div>
    @endunless

    <form method="POST" action="{{ route('codeguardian.store') }}" class="card">
        @csrf

        <div class="field">
            <label class="lbl" for="operation">Operation</label>
            <select name="operation" id="operation" onchange="cgSyncOperation()">
                @foreach($operations as $key => $spec)
                    <option value="{{ $key }}">{{ $spec['label'] }}</option>
                @endforeach
            </select>
            <div class="hint" id="op-hint"></div>
        </div>

        <div class="row">
            <div class="field">
                <label class="lbl" for="target_type">Target</label>
                <select name="target_type" id="target_type" onchange="cgSyncTarget()"></select>
            </div>
            <div class="field" id="target-value-wrap">
                <label class="lbl" for="target_value">Choose / search</label>
                <input type="text" name="target_value" id="target_value" list="" autocomplete="off" placeholder="">
                <div class="hint" id="target-hint"></div>
            </div>
        </div>

        {{-- Searchable option lists (native datalist — type to filter) --}}
        <datalist id="dl-module">
            @foreach($modules as $m)
                <option value="{{ $m }}"></option>
            @endforeach
        </datalist>
        <datalist id="dl-api">
            @foreach($apiRoutes as $r)
                <option value="{{ $r['uri'] }}">{{ $r['methods'] }} /{{ $r['uri'] }} — {{ $r['action'] }}{{ $r['name'] ? ' ('.$r['name'].')' : '' }}</option>
            @endforeach
        </datalist>
        <datalist id="dl-web">
            @foreach($webRoutes as $r)
                <option value="{{ $r['uri'] }}">{{ $r['methods'] }} /{{ $r['uri'] }} — {{ $r['action'] }}{{ $r['name'] ? ' ('.$r['name'].')' : '' }}</option>
            @endforeach
        </datalist>
        <datalist id="dl-command">
            @foreach($commands as $c)
                <option value="{{ $c['name'] }}">{{ $c['file'] }}</option>
            @endforeach
        </datalist>

        <div class="row">
            <div class="field">
                <label class="lbl" for="mode">Engine mode</label>
                <select name="mode" id="mode">
                    <option value="">Auto (use config)</option>
                    <option value="static">Static only</option>
                    <option value="hybrid">Hybrid (static + AI)</option>
                    <option value="ai">AI only</option>
                </select>
            </div>
            <div class="field" data-op="analyze">
                <label class="lbl" for="format">Report format</label>
                <select name="format" id="format">
                    <option value="both">HTML + JSON</option>
                    <option value="html">HTML</option>
                    <option value="json">JSON</option>
                </select>
            </div>
        </div>

        <div class="field" data-op="refactor">
            <label class="check">
                <input type="checkbox" name="with-existing-tests" value="1">
                Also run the project's existing tests (tests/Feature, tests/Unit) to catch breaking changes
            </label>
            <div class="hint">Requires a working local test DB/env. Refactor always runs in foolproof safe mode (test → refactor → verify → auto-rollback on regression).</div>
        </div>

        <div class="inline" style="margin-top: 8px;">
            <button type="submit" class="btn">Start run</button>
            <a href="{{ route('codeguardian.index') }}" class="btn secondary">Cancel</a>
        </div>
    </form>

    <script>
        const CG_OPERATIONS = @json($operations);
        const CG_TARGET_LABELS = @json($targetLabels);
        const CG_COUNTS = {
            module:  {{ count($modules) }},
            api:     {{ count($apiRoutes) }},
            web:     {{ count($webRoutes) }},
            command: {{ count($commands) }},
        };
        const CG_HINTS = {
            'analyze': 'Read-only review. Produces a graded report (architecture, security, performance, tech debt).',
            'refactor': 'Test-first foolproof refactor of the chosen target and its dependency chain.',
            'security': 'Senior-DevOps security audit: SQL injection, XSS, IDOR, broken auth, secrets.',
            'performance': 'Performance review: N+1 queries, missing indexes, caching, memory.',
            'generate-tests': 'Generate QA test cases (with assertions/mocks/edge cases) for the target.',
        };
        // Each target type → { datalist id | null, placeholder, freeText }
        const CG_TARGET_META = {
            project: { list: null,         ph: '(whole project — no target needed)', free: false, none: true },
            module:  { list: 'dl-module',  ph: 'Select a module…',                   free: false },
            api:     { list: 'dl-api',     ph: 'Search API routes by URI…',          free: false },
            web:     { list: 'dl-web',     ph: 'Search web routes by URI…',          free: false },
            command: { list: 'dl-command', ph: 'Search artisan commands by name…',   free: false },
            file:    { list: null,         ph: 'app/Services/AuthService.php',        free: true },
            path:    { list: null,         ph: 'app/Http/Controllers (directory)',   free: true },
        };

        function cgSyncOperation() {
            const op = document.getElementById('operation').value;
            const spec = CG_OPERATIONS[op] || { targets: ['project'], options: [] };

            // Rebuild the target-type dropdown for this operation.
            const sel = document.getElementById('target_type');
            sel.innerHTML = '';
            (spec.targets || ['project']).forEach(function (t) {
                const opt = document.createElement('option');
                opt.value = t;
                let label = CG_TARGET_LABELS[t] || t;
                if (CG_COUNTS[t] !== undefined) label += ' (' + CG_COUNTS[t] + ')';
                opt.textContent = label;
                sel.appendChild(opt);
            });

            // Toggle operation-specific options (format/with-existing-tests).
            document.querySelectorAll('[data-op]').forEach(function (el) {
                el.style.display = el.getAttribute('data-op') === op ? '' : 'none';
            });

            document.getElementById('op-hint').textContent = CG_HINTS[op] || '';
            cgSyncTarget();
        }

        function cgSyncTarget() {
            const type = document.getElementById('target_type').value;
            const meta = CG_TARGET_META[type] || { list: null, ph: '', free: true };
            const input = document.getElementById('target_value');
            const wrap = document.getElementById('target-value-wrap');
            const hint = document.getElementById('target-hint');

            if (meta.none) {
                wrap.style.display = 'none';
                input.value = '';
                hint.textContent = '';
                return;
            }
            wrap.style.display = '';
            input.placeholder = meta.ph || '';
            if (meta.list && document.getElementById(meta.list)) {
                input.setAttribute('list', meta.list);
                const n = CG_COUNTS[type];
                hint.textContent = (n === 0)
                    ? 'None found in this project — you can still type a value.'
                    : 'Type to filter the list, or paste a value.';
            } else {
                input.removeAttribute('list');
                hint.textContent = meta.free ? 'Type a path relative to the project root.' : '';
            }
        }

        cgSyncOperation();
    </script>
@endsection
