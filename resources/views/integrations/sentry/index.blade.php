@extends('codeguardian::layout')
@section('title', 'Sentry · CodeGuardian')

@section('content')
    <h1>Sentry</h1>
    <p class="muted">Production exceptions across your project — filter, investigate, and jump to the exact line.</p>

    @if(! $configured)
        @include('codeguardian::integrations._setup', [
            'title' => 'Sentry',
            'icon'  => '▲',
            'intro' => 'Pull unresolved production issues, trace each to the offending file, and (from the CLI) auto-fix them.',
            'missing' => $missing ?? [],
            'envExample' =>
"CODEGUARDIAN_SENTRY_TOKEN=xxxxx      # Sentry auth token (event:read, project:read)\n".
"CODEGUARDIAN_SENTRY_ORG=your-org-slug\n".
"CODEGUARDIAN_SENTRY_PROJECT=your-project-slug\n".
"# Optional:\n".
"CODEGUARDIAN_SENTRY_URL=https://sentry.io\n".
"CODEGUARDIAN_SENTRY_ENVIRONMENT=production",
            'docs' => 'Create a token under <span class="mono">Sentry → Settings → Auth Tokens</span>.',
        ])
    @else
        <form method="GET" class="card" style="padding:16px">
            <div class="inline" style="gap:12px; align-items:flex-end">
                <div class="field" style="margin:0; min-width:150px">
                    <label class="lbl">Status</label>
                    <select name="status">
                        @foreach($statuses as $s)
                            <option value="{{ $s }}" @selected(($filters['status'] ?? '')===$s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="margin:0; min-width:130px">
                    <label class="lbl">Level</label>
                    <select name="level">
                        <option value="">Any</option>
                        @foreach($levels as $l)
                            <option value="{{ $l }}" @selected(($filters['level'] ?? '')===$l)>{{ ucfirst($l) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="margin:0; min-width:160px">
                    <label class="lbl">Environment</label>
                    <select name="environment">
                        <option value="">All</option>
                        @foreach($environments as $e)
                            <option value="{{ $e }}" @selected(($filters['environment'] ?? '')===$e)>{{ $e }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="margin:0; min-width:150px">
                    <label class="lbl">Project</label>
                    <select name="project">
                        @if(empty($projects))
                            <option value="">{{ $currentProject }}</option>
                        @else
                            @foreach($projects as $p)
                                <option value="{{ $p['slug'] }}" @selected($currentProject===$p['slug'])>{{ $p['name'] }}</option>
                            @endforeach
                        @endif
                    </select>
                </div>
                <div class="field" style="margin:0; min-width:120px">
                    <label class="lbl">Date</label>
                    <select name="period">
                        @foreach($periods as $p)
                            <option value="{{ $p }}" @selected(($filters['period'] ?? '')===$p)>Last {{ $p }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn" type="submit">Filter</button>
            </div>
        </form>

        <div class="card" style="padding:0">
            @if(empty($issues))
                <div class="empty">No issues match these filters. 🎉</div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Issue</th><th>Level</th><th>Events</th><th>Users</th><th>Last seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($issues as $it)
                            <tr>
                                <td>
                                    <a href="{{ route('codeguardian.sentry.show', ['id' => $it['id']]) }}" style="font-weight:600">{{ $it['title'] }}</a>
                                    @if($it['shortId'])<span class="pill type" style="margin-left:6px">{{ $it['shortId'] }}</span>@endif
                                    @if($it['culprit'])<div class="mono">{{ $it['culprit'] }}</div>@endif
                                </td>
                                <td><span class="sevtag {{ in_array($it['level'],['fatal','error'])?'high':($it['level']==='warning'?'medium':'low') }}">{{ $it['level'] }}</span></td>
                                <td>{{ number_format($it['count']) }}</td>
                                <td>{{ number_format($it['userCount']) }}</td>
                                <td class="muted">{{ $it['lastSeen'] ? \Illuminate\Support\Carbon::parse($it['lastSeen'])->diffForHumans() : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <p class="muted">Tip: use the CLI to auto-fix — <span class="mono">php artisan codeguardian:sentry --fix --apply --with-tests</span></p>
    @endif
@endsection
