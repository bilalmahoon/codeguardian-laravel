@extends('codeguardian::layout')
@section('title', 'Slack · CodeGuardian')

@section('content')
    <h1>Slack</h1>
    <p class="muted">Development & incident chatter from your team's channels, right here.</p>

    @if(! $configured)
        @include('codeguardian::integrations._setup', [
            'title' => 'Slack',
            'icon'  => '#',
            'intro' => 'Read recent messages from the channels your team uses for alerts and incidents, and (from the CLI/App) drive fixes from Slack.',
            'missing' => $missing ?? [],
            'envExample' =>
"CODEGUARDIAN_SLACK_BOT_TOKEN=xoxb-...            # bot token, scopes: channels:history, channels:read\n".
"CODEGUARDIAN_SLACK_CHANNELS=C0123456:alerts,C0789012:incidents",
            'docs' => 'Create a bot token at <span class="mono">api.slack.com/apps</span> and invite the bot to each channel.',
        ])
    @else
        <div class="card" style="padding:16px">
            <form method="GET" class="inline" style="gap:12px; align-items:flex-end">
                <div class="field" style="margin:0; min-width:240px">
                    <label class="lbl">Channel</label>
                    <select name="channel" onchange="this.form.submit()">
                        @foreach($channels as $ch)
                            <option value="{{ $ch['id'] }}" @selected(($current ?? '')===$ch['id'])># {{ $ch['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn secondary" type="submit">Refresh</button>
            </form>
        </div>

        <div class="card">
            <h2># {{ $currentLabel ?? '' }}</h2>
            @if(empty($messages))
                <div class="empty">No recent messages (or the bot isn't in this channel yet).</div>
            @else
                @foreach($messages as $m)
                    <div class="finding">
                        <div class="loc">{{ $m['user'] }} · {{ $m['time'] }}</div>
                        <div class="desc" style="color:var(--text); white-space:pre-wrap">{{ $m['text'] }}</div>
                    </div>
                @endforeach
            @endif
            <p class="hint">Read-only for now. Future actions (reply, resolve, assign) will appear per message.</p>
        </div>
    @endif
@endsection
