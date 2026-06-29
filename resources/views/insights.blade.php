@extends('codeguardian::layout')

@section('title', 'CodeGuardian — Insights')

@php
    $points  = $trend['points'] ?? [];
    $latest  = $trend['latest'] ?? null;
    $score   = (int) ($latest['score'] ?? ($report['overall_score'] ?? 0));
    $grade   = (string) ($latest['grade'] ?? ($report['grade'] ?? '—'));
    $risk    = (int) ($latest['risk'] ?? ($report['summary']['risk_score'] ?? 0));
    $total   = (int) ($latest['total'] ?? ($severity['total'] ?? 0));
    $scoreClass = $score >= 80 ? 'good' : ($score >= 60 ? 'warn' : 'bad');
    $riskClass  = $risk >= 45 ? 'bad' : ($risk >= 20 ? 'warn' : 'good');
    $dirArrow = ['up' => '▲', 'down' => '▼', 'flat' => '▬'][$trend['direction'] ?? 'flat'];
    $dims = $report['quality']['dimensions'] ?? [];
    $sevMax = max(1, (int) ($severity['critical'] ?? 0), (int) ($severity['high'] ?? 0), (int) ($severity['medium'] ?? 0), (int) ($severity['low'] ?? 0));
    $catMax = 1;
    foreach (($categories ?? []) as $c) { $catMax = max($catMax, (int) $c['count']); }
@endphp

@section('content')
    <h1>Insights</h1>
    <p class="muted">Code-health trends over time, plus a breakdown of the latest analysis.</p>

    @if(empty($points) && empty($report))
        <div class="card">
            <div class="empty">
                No data yet.<br>
                <span class="muted">Run an analysis to start tracking trends.</span><br><br>
                <a href="{{ route('codeguardian.create') }}" class="btn">+ New analysis</a>
            </div>
        </div>
    @else
        <div class="card">
            <div class="stats">
                <div class="stat">
                    <div class="k">Overall score</div>
                    <div class="v {{ $scoreClass }}">{{ $score }}<span class="muted" style="font-size:14px;">/100 · {{ $grade }}</span></div>
                </div>
                <div class="stat">
                    <div class="k">Risk score</div>
                    <div class="v {{ $riskClass }}">{{ $risk }}<span class="muted" style="font-size:14px;">/100</span></div>
                </div>
                <div class="stat">
                    <div class="k">Total issues</div>
                    <div class="v">{{ $total }}</div>
                </div>
                <div class="stat">
                    <div class="k">Score trend</div>
                    <div class="v"><span class="delta {{ $trend['direction'] }}">{{ $dirArrow }} {{ $trend['delta'] > 0 ? '+' : '' }}{{ $trend['delta'] }}</span></div>
                </div>
            </div>
        </div>

        @if(count($points) >= 1)
        <div class="row" style="margin-top:20px;">
            <div class="card" style="margin-top:0;">
                <h2>Quality score over time</h2>
                <div class="chart">
                    {!! $scoreSpark !!}
                    <div class="lbls">
                        <span>{{ $points[0]['label'] ?? '' }}</span>
                        <span>{{ $points[count($points)-1]['label'] ?? '' }}</span>
                    </div>
                </div>
            </div>
            <div class="card" style="margin-top:0;">
                <h2>Risk over time</h2>
                <div class="chart">
                    {!! $riskSpark !!}
                    <div class="lbls">
                        <span>{{ $points[0]['label'] ?? '' }}</span>
                        <span>{{ $points[count($points)-1]['label'] ?? '' }}</span>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <div class="card">
            <h2>Severity breakdown</h2>
            @foreach(['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $key => $lbl)
                @php $val = (int) ($severity[$key] ?? 0); @endphp
                <div class="qbar">
                    <span><span class="dot sev-{{ $key === 'critical' ? 'crit' : ($key === 'medium' ? 'med' : $key) }}"></span>{{ $lbl }}</span>
                    <div class="bar"><span class="sev-{{ $key === 'critical' ? 'crit' : ($key === 'medium' ? 'med' : $key) }}" style="width: {{ (int) round($val / $sevMax * 100) }}%"></span></div>
                    <span class="muted" style="text-align:right;">{{ $val }}</span>
                </div>
            @endforeach
        </div>

        @if(!empty($dims))
        <div class="card">
            <h2>Quality dimensions</h2>
            @foreach($dims as $dim)
                @php
                    $ds = (int) ($dim['score'] ?? 0);
                    $dc = $ds >= 80 ? 'sev-low' : ($ds >= 60 ? 'sev-med' : 'sev-crit');
                @endphp
                <div class="qbar">
                    <span>{{ $dim['label'] ?? ($dim['key'] ?? '') }}</span>
                    <div class="bar"><span class="{{ $dc }}" style="width: {{ $ds }}%"></span></div>
                    <span class="muted" style="text-align:right;">{{ $ds }}/100</span>
                </div>
            @endforeach
        </div>
        @endif

        @if(!empty($categories))
        <div class="card">
            <h2>Top issue categories</h2>
            @foreach($categories as $c)
                <div class="catrow">
                    <span class="nm"><span class="sevtag {{ $c['severity'] }}">{{ strtoupper($c['severity']) }}</span></span>
                    <div>
                        <div class="nm" style="margin-bottom:4px;">{{ \Illuminate\Support\Str::headline($c['category']) }}</div>
                        <div class="bar"><span class="sev-{{ $c['severity'] === 'critical' ? 'crit' : ($c['severity'] === 'medium' ? 'med' : $c['severity']) }}" style="width: {{ (int) round($c['count'] / $catMax * 100) }}%"></span></div>
                    </div>
                    <span class="muted" style="text-align:right;">{{ $c['count'] }}</span>
                </div>
            @endforeach
        </div>
        @endif

        @if(!empty($hotspots))
        <div class="card">
            <h2>Hotspot files</h2>
            <table>
                <thead><tr><th>File</th><th style="text-align:right;">Issues</th></tr></thead>
                <tbody>
                    @foreach($hotspots as $file => $count)
                        <tr>
                            <td class="mono">{{ $file }}</td>
                            <td style="text-align:right;">{{ $count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    @endif
@endsection
