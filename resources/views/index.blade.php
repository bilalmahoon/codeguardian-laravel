@extends('codeguardian::layout')

@section('title', 'CodeGuardian — History')

@section('content')
    <h1>Scan & refactor history</h1>
    <p class="muted">Every analysis, security audit, performance review, test generation and refactor — with live progress and saved results.</p>

    @php $latest = $trend['latest'] ?? null; @endphp
    @if($latest)
        @php
            $score = (int) ($latest['score'] ?? 0);
            $scoreClass = $score >= 80 ? 'good' : ($score >= 60 ? 'warn' : 'bad');
            $dirArrow = ['up' => '▲', 'down' => '▼', 'flat' => '▬'][$trend['direction'] ?? 'flat'];
        @endphp
        <div class="card">
            <div class="inline" style="margin-bottom: 6px;">
                <h2 style="margin:0;">Project health</h2>
                <div class="grow"></div>
                <a href="{{ route('codeguardian.insights') }}">View insights →</a>
            </div>
            <div class="stats">
                <div class="stat"><div class="k">Score</div><div class="v {{ $scoreClass }}">{{ $score }}<span class="muted" style="font-size:14px;">/100 · {{ $latest['grade'] ?? '—' }}</span></div></div>
                <div class="stat"><div class="k">Risk</div><div class="v">{{ (int) ($latest['risk'] ?? 0) }}<span class="muted" style="font-size:14px;">/100</span></div></div>
                <div class="stat"><div class="k">Open issues</div><div class="v">{{ (int) ($latest['total'] ?? 0) }}</div></div>
                <div class="stat"><div class="k">Trend</div><div class="v"><span class="delta {{ $trend['direction'] }}">{{ $dirArrow }} {{ ($trend['delta'] ?? 0) > 0 ? '+' : '' }}{{ $trend['delta'] ?? 0 }}</span></div></div>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="inline" style="margin-bottom: 6px;">
            <h2 style="margin:0;">Runs</h2>
            <div class="grow"></div>
            <a href="{{ route('codeguardian.create') }}" class="btn">+ New run</a>
        </div>

        @if(empty($runs))
            <div class="empty">
                No runs yet.<br>
                <span class="muted">Start your first analysis or refactor with “New run”.</span>
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Type</th>
                        <th>Target</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($runs as $run)
                        <tr>
                            <td class="mono">{{ \Illuminate\Support\Str::of($run['id'])->before('_'.\Illuminate\Support\Str::afterLast($run['id'], '_')) }}</td>
                            <td><span class="pill type">{{ $run['type'] }}</span></td>
                            <td>{{ \Illuminate\Support\Str::after($run['label'] ?? '', ': ') ?: '—' }}</td>
                            <td><span class="pill {{ $run['status'] }}">{{ $run['status'] }}</span></td>
                            <td style="text-align:right;">
                                <a href="{{ route('codeguardian.show', ['id' => $run['id']]) }}">Open →</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
