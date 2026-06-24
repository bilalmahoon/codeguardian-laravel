@extends('codeguardian::layout')

@section('title', 'CodeGuardian — New run')

@section('content')
    <h1>New run</h1>
    <p class="muted">Pick an operation and a target. The run starts in the background and streams live progress.</p>

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
            <select name="operation" id="operation" onchange="cgSync()">
                @foreach($operations as $key => $spec)
                    <option value="{{ $key }}">{{ $spec['label'] }}</option>
                @endforeach
            </select>
            <div class="hint" id="op-hint"></div>
        </div>

        <div class="field" data-opt="api">
            <label class="lbl" for="api">API route filter</label>
            <input type="text" name="api" id="api" placeholder="v1/auth/login">
            <div class="hint">Refactors/analyzes the exact route handler and its dependency chain (controller → service → repository).</div>
        </div>

        <div class="field" data-opt="module">
            <label class="lbl" for="module">Module</label>
            <input type="text" name="module" id="module" placeholder="UserAuthentication">
            <div class="hint">Limit to a single module (nWidart-style Modules/, app/Modules/, app/Domain/).</div>
        </div>

        <div class="field" data-opt="file">
            <label class="lbl" for="file">Single file</label>
            <input type="text" name="file" id="file" placeholder="app/Services/AuthService.php">
            <div class="hint">Target one file plus its traced dependency chain.</div>
        </div>

        <div class="field" data-opt="path">
            <label class="lbl" for="path">Path</label>
            <input type="text" name="path" id="path" placeholder="(leave empty for whole project)">
            <div class="hint">Directory to scan. Leave empty to scan the whole project.</div>
        </div>

        <div class="row">
            <div class="field" data-opt="mode">
                <label class="lbl" for="mode">Engine mode</label>
                <select name="mode" id="mode">
                    <option value="">Auto (use config)</option>
                    <option value="static">Static only</option>
                    <option value="hybrid">Hybrid (static + AI)</option>
                    <option value="ai">AI only</option>
                </select>
            </div>
            <div class="field" data-opt="format">
                <label class="lbl" for="format">Report format</label>
                <select name="format" id="format">
                    <option value="both">HTML + JSON</option>
                    <option value="html">HTML</option>
                    <option value="json">JSON</option>
                </select>
            </div>
        </div>

        <div class="field" data-opt="with-existing-tests">
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
        // Which target fields each operation accepts (mirrors controller whitelist).
        const CG_OPTS = {
            'analyze':        ['api','module','path','mode','format'],
            'refactor':       ['api','module','file','path','mode','with-existing-tests'],
            'security':       ['path','mode'],
            'performance':    ['path','mode'],
            'generate-tests': ['file','path','mode'],
        };
        const CG_HINTS = {
            'analyze': 'Read-only review. Produces a graded report (architecture, security, performance, tech debt).',
            'refactor': 'Test-first foolproof refactor of the route/module/file and its dependency chain.',
            'security': 'Senior-DevOps security audit: SQL injection, XSS, IDOR, broken auth, secrets.',
            'performance': 'Performance review: N+1 queries, missing indexes, caching, memory.',
            'generate-tests': 'Generate QA test cases (with assertions/mocks/edge cases) for the target.',
        };
        function cgSync() {
            const op = document.getElementById('operation').value;
            const allowed = CG_OPTS[op] || [];
            document.querySelectorAll('[data-opt]').forEach(el => {
                el.style.display = allowed.includes(el.getAttribute('data-opt')) ? '' : 'none';
            });
            document.getElementById('op-hint').textContent = CG_HINTS[op] || '';
        }
        cgSync();
    </script>
@endsection
