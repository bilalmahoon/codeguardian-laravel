@extends('codeguardian::layout')

@section('title', 'CodeGuardian — Run')

@section('content')
    <div class="inline" style="margin-top: 24px;">
        <a href="{{ route('codeguardian.index') }}" class="muted">← History</a>
    </div>

    <h1 style="margin-top: 8px;">{{ $run['label'] ?? $run['id'] }}</h1>
    <div class="inline">
        <span class="pill type">{{ $run['type'] }}</span>
        <span class="pill {{ $run['status'] }}" id="status-pill">{{ $run['status'] }}</span>
        <span class="mono">{{ $run['command'] ?? '' }}</span>
    </div>

    <div class="card">
        <div class="inline" style="margin-bottom: 12px;">
            <h2 style="margin:0;">Live output</h2>
            <div class="grow"></div>
            <span class="muted" id="live-indicator"></span>
        </div>
        <div class="console" id="console"></div>
    </div>

    <div class="card" id="reports-card" style="{{ empty($reports) ? 'display:none;' : '' }}">
        <h2>Results</h2>
        <div id="reports-list" class="inline">
            @foreach($reports as $report)
                @if($report['ext'] === 'html')
                    <a class="btn secondary" href="{{ route('codeguardian.report', ['id' => $run['id']]) }}" target="_blank">📄 View HTML report</a>
                @else
                    <span class="pill type">{{ $report['name'] }}</span>
                @endif
            @endforeach
        </div>
        <p class="hint">Reports are also saved to <span class="mono">storage/{{ config('codeguardian.output.report_dir', 'codeguardian/reports') }}</span>.</p>

        @if(($run['type'] ?? null) === 'analyze')
            <div id="fix-cta" class="inline" style="margin-top:14px; {{ in_array($run['status'], ['completed','failed'], true) ? '' : 'display:none;' }}">
                <form method="POST" action="{{ route('codeguardian.fix', ['id' => $run['id']]) }}"
                      onsubmit="return confirm('Start fixing these issues? Changes are test-verified and auto-rolled-back if anything breaks.');">
                    @csrf
                    <button type="submit" class="btn">🔧 Fix these issues</button>
                </form>
                <span class="hint">Runs a safe, test-verified refactor over the same scope.</span>
            </div>
        @endif
    </div>

    <form method="POST" action="{{ route('codeguardian.destroy', ['id' => $run['id']]) }}"
          onsubmit="return confirm('Delete this run from history?');" style="margin-top: 18px;">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn danger">Delete run</button>
    </form>

    <script>
        (function () {
            const runId   = @json($run['id']);
            const statusUrl = @json(route('codeguardian.status', ['id' => $run['id']]));
            const reportUrl = @json(route('codeguardian.report', ['id' => $run['id']]));
            let offset = 0;
            let finished = {{ in_array($run['status'], ['completed','failed'], true) ? 'true' : 'false' }};

            const consoleEl = document.getElementById('console');
            const pill = document.getElementById('status-pill');
            const indicator = document.getElementById('live-indicator');
            const reportsCard = document.getElementById('reports-card');
            const reportsList = document.getElementById('reports-list');

            function atBottom() {
                return consoleEl.scrollHeight - consoleEl.scrollTop - consoleEl.clientHeight < 40;
            }

            function setStatus(s) {
                pill.textContent = s;
                pill.className = 'pill ' + s;
            }

            function renderReports(reports) {
                if (!reports || !reports.length) return;
                reportsCard.style.display = '';
                let html = '';
                reports.forEach(function (r) {
                    if (r.ext === 'html') {
                        html += '<a class="btn secondary" href="' + reportUrl + '" target="_blank">📄 View HTML report</a>';
                    } else {
                        html += '<span class="pill type">' + r.name + '</span>';
                    }
                });
                reportsList.innerHTML = html;
            }

            async function poll() {
                try {
                    const res = await fetch(statusUrl + '?offset=' + offset, { headers: { 'Accept': 'application/json' } });
                    if (!res.ok) { indicator.textContent = ''; return; }
                    const data = await res.json();

                    if (data.chunk) {
                        const stick = atBottom();
                        consoleEl.textContent += data.chunk;
                        if (stick) consoleEl.scrollTop = consoleEl.scrollHeight;
                    }
                    offset = data.offset;
                    setStatus(data.status);
                    renderReports(data.reports);

                    if (data.finished) {
                        finished = true;
                        indicator.textContent = data.status === 'completed' ? '✔ finished' : '✖ failed (exit ' + data.exit_code + ')';
                        var fixCta = document.getElementById('fix-cta');
                        if (fixCta) fixCta.style.display = '';
                        return;
                    }
                    indicator.textContent = '● running…';
                } catch (e) {
                    indicator.textContent = '';
                } finally {
                    if (!finished) setTimeout(poll, 1500);
                }
            }

            // Prime the full log then keep polling.
            poll();
        })();
    </script>
@endsection
