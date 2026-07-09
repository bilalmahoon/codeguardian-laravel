@extends('codeguardian::layout')
@section('title', ($summary['title'] ?? 'Issue') . ' · Sentry')

@section('content')
    <p class="muted" style="margin-top:20px"><a href="{{ route('codeguardian.sentry.index') }}">← Back to Sentry</a></p>
    <h1 style="margin-top:6px">{{ $summary['title'] }}</h1>
    <div class="inline">
        <span class="sevtag {{ in_array($summary['level'],['fatal','error'])?'high':($summary['level']==='warning'?'medium':'low') }}">{{ $summary['level'] }}</span>
        <span class="pill type">{{ ucfirst($summary['status']) }}</span>
        @if($summary['shortId'])<span class="mono">{{ $summary['shortId'] }}</span>@endif
        @if($summary['permalink'])<a href="{{ $summary['permalink'] }}" target="_blank" rel="noopener">Open in Sentry ↗</a>@endif
    </div>

    <div class="stats" style="margin-top:20px">
        <div class="stat"><div class="k">Events</div><div class="v">{{ number_format($summary['count']) }}</div></div>
        <div class="stat"><div class="k">Users affected</div><div class="v">{{ number_format($summary['userCount']) }}</div></div>
        <div class="stat"><div class="k">First seen</div><div class="v" style="font-size:15px">{{ $summary['firstSeen'] ? \Illuminate\Support\Carbon::parse($summary['firstSeen'])->diffForHumans() : '—' }}</div></div>
        <div class="stat"><div class="k">Last seen</div><div class="v" style="font-size:15px">{{ $summary['lastSeen'] ? \Illuminate\Support\Carbon::parse($summary['lastSeen'])->diffForHumans() : '—' }}</div></div>
    </div>

    @if(!empty($exception['type']) || !empty($exception['value']))
        <div class="card">
            <h2>Exception</h2>
            <div class="finding">
                <div class="ttl">{{ $exception['type'] }}</div>
                <div class="desc">{{ $exception['value'] }}</div>
            </div>
        </div>
    @endif

    @if($frame)
        <div class="card">
            <h2>Crash location</h2>
            <div class="mono" style="margin-bottom:10px">
                {{ $frame['filename'] }}:{{ $frame['lineno'] }}
                @if($frame['function']) — <span style="color:var(--accent)">{{ $frame['function'] }}()</span>@endif
            </div>
            @if($localPath)
                <div class="banner ok">Resolved in this project: <span class="mono">{{ $localPath }}</span></div>
            @else
                <div class="banner warn">Could not map this path to a file in the current project.</div>
            @endif

            @if(!empty($frame['context']))
                <div class="console" style="margin-top:12px">@foreach($frame['context'] as $line)<div><span style="color:var(--muted)">{{ str_pad((string)$line[0], 4, ' ', STR_PAD_LEFT) }}</span>  {{ $line[1] }}</div>@endforeach</div>
            @endif
        </div>
    @endif

    <div class="card">
        <h2>Fix this issue</h2>
        <p class="muted">CodeGuardian can generate a <strong>safe, test-verified</strong> fix for this exception —
            it writes the change with a backup, runs your tests, rolls back on failure, and resolves the issue in Sentry on success.</p>

        <form method="POST" action="{{ route('codeguardian.sentry.fix', ['id' => $summary['id']]) }}" style="margin:14px 0">
            @csrf
            <button type="submit" class="btn"
                    onclick="return confirm('Run a safe auto-fix for this issue?\n\nIt writes the fix, runs tests, rolls back on failure, and resolves in Sentry on success.');">
                ▶ Run safe auto-fix
            </button>
        </form>

        <p class="muted" style="margin-bottom:6px">Prefer the terminal? Run the same thing from the CLI:</p>
        <div class="console" style="max-height:none">php artisan codeguardian:sentry --issue={{ $summary['id'] }} --fix --apply --with-tests --resolve</div>
        <p class="hint">Future dashboard actions (ignore, assign, comment) will appear here.</p>
    </div>
@endsection
