@extends('codeguardian::layout')
@section('title', 'Message · Slack')

@section('content')
    <p class="muted" style="margin-top:20px"><a href="{{ route('codeguardian.slack.index', ['channel' => $channelId]) }}">← Back to #{{ $channelLabel }}</a></p>
    <h1 style="margin-top:6px">Message in #{{ $channelLabel }}</h1>
    <div class="inline">
        <span class="pill type">{{ $message['user'] }}</span>
        <span class="muted">{{ $message['time'] }}</span>
        @if($permalink)<a href="{{ $permalink }}" target="_blank" rel="noopener">Open in Slack ↗</a>@endif
    </div>

    <div class="card">
        <h2>Content</h2>
        <div class="console" style="max-height:none; white-space:pre-wrap">{{ $message['text'] }}</div>
    </div>

    <div class="card">
        <h2>Investigate</h2>
        <p class="muted">Cross-reference this alert with your code and production errors:</p>
        <div class="inline">
            <a class="btn secondary" href="{{ route('codeguardian.sentry.index') }}">▲ Open Sentry issues</a>
            <a class="btn secondary" href="{{ route('codeguardian.create') }}">+ New analysis / refactor</a>
        </div>
        <p class="hint">Future per-message actions (reply, resolve, assign) will appear here.</p>
    </div>
@endsection
