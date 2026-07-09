{{-- Reusable "integration not configured yet" card.
     Expects: $title, $icon, $intro, $missing (array), $envExample (string), $docs (string|null) --}}
<div class="card">
    <h2>{{ $icon ?? '🔌' }} Connect {{ $title }}</h2>
    <p class="muted" style="margin-top:-6px">{{ $intro }}</p>

    @if(!empty($missing))
        <div class="banner warn">Missing configuration: <span class="mono">{{ implode(', ', $missing) }}</span></div>
    @endif

    <p class="muted" style="margin-top:16px">Add the following to your <span class="mono">.env</span>, then reload:</p>
    <div class="console" style="max-height:none">{{ $envExample }}</div>

    @if(!empty($docs))
        <p class="muted" style="margin-top:14px">{!! $docs !!}</p>
    @endif
</div>
