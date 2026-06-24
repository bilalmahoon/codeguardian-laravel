<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CodeGuardian')</title>
    <style>
        :root {
            --bg: #0b0e14; --panel: #141925; --panel-2: #1b2230; --border: #28304180;
            --text: #e6e9ef; --muted: #8a93a6; --accent: #5b8cff; --accent-2: #7c5bff;
            --green: #3fb950; --red: #f85149; --amber: #d29922; --mono: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 0 20px 60px; }
        header.top { border-bottom: 1px solid var(--border); background: #0d111a;
            position: sticky; top: 0; z-index: 10; }
        header.top .wrap { display: flex; align-items: center; gap: 16px; padding: 14px 20px; }
        .brand { font-weight: 700; font-size: 18px; letter-spacing: .3px; }
        .brand span { background: linear-gradient(90deg, var(--accent), var(--accent-2));
            -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; }
        .grow { flex: 1; }
        .btn { display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
            background: var(--accent); color: #fff; border: none; border-radius: 8px;
            padding: 9px 16px; font-size: 14px; font-weight: 600; }
        .btn:hover { filter: brightness(1.08); text-decoration: none; }
        .btn.secondary { background: var(--panel-2); color: var(--text); border: 1px solid var(--border); }
        .btn.danger { background: transparent; color: var(--red); border: 1px solid #f8514955; padding: 6px 12px; font-size: 13px; }
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-top: 20px; }
        h1 { font-size: 22px; margin: 24px 0 4px; }
        h2 { font-size: 16px; margin: 0 0 14px; color: var(--text); }
        .muted { color: var(--muted); font-size: 13px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid var(--border); font-size: 14px; }
        th { color: var(--muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        tr:hover td { background: #ffffff05; }
        .pill { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .pill.running { background: #1f6feb22; color: #6ea8ff; }
        .pill.completed { background: #3fb95022; color: var(--green); }
        .pill.failed { background: #f8514922; color: var(--red); }
        .pill.type { background: var(--panel-2); color: var(--muted); border: 1px solid var(--border); }
        .field { margin-bottom: 16px; }
        label.lbl { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        input[type=text], select { width: 100%; background: var(--panel-2); color: var(--text);
            border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; font-size: 14px; }
        input[type=text]:focus, select:focus { outline: none; border-color: var(--accent); }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .hint { color: var(--muted); font-size: 12px; margin-top: 4px; }
        .console { background: #05070b; border: 1px solid var(--border); border-radius: 10px;
            padding: 16px; font-family: var(--mono); font-size: 12.5px; line-height: 1.6;
            white-space: pre-wrap; word-break: break-word; max-height: 520px; overflow-y: auto; color: #c9d4e5; }
        .banner { padding: 10px 14px; border-radius: 8px; margin-top: 16px; font-size: 14px; }
        .banner.err { background: #f8514922; color: #ffb4ae; border: 1px solid #f8514944; }
        .banner.ok { background: #3fb95022; color: #9ff0a9; border: 1px solid #3fb95044; }
        .banner.warn { background: #d2992222; color: #f3d27a; border: 1px solid #d2992244; }
        .empty { text-align: center; color: var(--muted); padding: 40px 0; }
        .inline { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .mono { font-family: var(--mono); font-size: 12.5px; color: var(--muted); }
        .check { display: flex; align-items: center; gap: 8px; font-size: 14px; }
    </style>
</head>
<body>
    <header class="top">
        <div class="wrap">
            <a href="{{ route('codeguardian.index') }}" class="brand"><span>◆ CodeGuardian</span></a>
            <div class="grow"></div>
            @if(!empty($aiReady))
                <span class="mono">
                    {{ $aiReady['enabled'] ? '🤖 AI: '.$aiReady['provider'].' / '.$aiReady['model'] : '⚡ Static only (no AI key)' }}
                </span>
            @endif
            <a href="{{ route('codeguardian.create') }}" class="btn">+ New run</a>
        </div>
    </header>

    <div class="wrap">
        @if(session('cg_status'))
            <div class="banner ok">{{ session('cg_status') }}</div>
        @endif
        @if(session('cg_error'))
            <div class="banner err">{{ session('cg_error') }}</div>
        @endif

        @yield('content')
    </div>
</body>
</html>
