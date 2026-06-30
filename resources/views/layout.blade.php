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
        .navlink { color: var(--muted); font-size: 14px; font-weight: 600; }
        .navlink:hover { color: var(--text); text-decoration: none; }
        /* Insights / explorer components */
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
        @media (max-width: 720px) { .stats { grid-template-columns: repeat(2, 1fr); } }
        .stat { background: var(--panel-2); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; }
        .stat .k { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
        .stat .v { font-size: 26px; font-weight: 700; margin-top: 6px; }
        .stat .v.good { color: var(--green); } .stat .v.warn { color: var(--amber); } .stat .v.bad { color: var(--red); }
        .delta { font-size: 12px; font-weight: 600; }
        .delta.up { color: var(--green); } .delta.down { color: var(--red); } .delta.flat { color: var(--muted); }
        .chart { background: #05070b; border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; }
        .chart .lbls { display: flex; justify-content: space-between; color: var(--muted); font-size: 11px; margin-top: 4px; }
        .bar { height: 10px; background: var(--panel-2); border-radius: 999px; overflow: hidden; }
        .bar > span { display: block; height: 100%; border-radius: 999px; }
        .sev-crit { background: var(--red); } .sev-high { background: #ff7b3d; }
        .sev-med { background: var(--amber); } .sev-low { background: var(--green); }
        .catrow { display: grid; grid-template-columns: 180px 1fr 48px; gap: 12px; align-items: center; padding: 7px 0; }
        .catrow .nm { font-size: 13px; }
        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
        .sevtag { display:inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .sevtag.critical { background: #f8514922; color: #ff9a93; }
        .sevtag.high { background: #ff7b3d22; color: #ffb38a; }
        .sevtag.medium { background: #d2992222; color: #f3d27a; }
        .sevtag.low { background: #3fb95022; color: #9ff0a9; }
        .fbar { display:flex; gap: 6px; flex-wrap: wrap; margin: 12px 0; }
        .fbtn { cursor: pointer; background: var(--panel-2); border: 1px solid var(--border); color: var(--text);
            border-radius: 8px; padding: 6px 12px; font-size: 13px; font-weight: 600; }
        .fbtn.active { border-color: var(--accent); color: var(--accent); }
        .finding { border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; background: var(--panel-2); }
        .finding .ttl { font-size: 14px; font-weight: 600; }
        .finding .loc { font-family: var(--mono); font-size: 12px; color: var(--muted); margin-top: 4px; }
        .finding .desc { font-size: 13px; color: var(--muted); margin-top: 8px; }
        .qbar { display: grid; grid-template-columns: 140px 1fr 60px; gap: 12px; align-items: center; padding: 6px 0; font-size: 13px; }
        /* Searchable combobox (custom, scrollable + resizable dropdown) */
        .cg-combo { position: relative; }
        .cg-combo .clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            cursor: pointer; color: var(--muted); font-size: 16px; line-height: 1; padding: 2px 4px; display: none; }
        .cg-combo .clear:hover { color: var(--text); }
        .cg-panel { position: absolute; left: 0; right: 0; top: calc(100% + 5px); z-index: 60;
            background: var(--panel-2); border: 1px solid var(--border); border-radius: 10px;
            max-height: 300px; min-height: 44px; overflow-y: auto; resize: vertical;
            box-shadow: 0 14px 36px #000a; display: none; }
        .cg-panel.open { display: block; }
        .cg-panel::-webkit-scrollbar { width: 10px; }
        .cg-panel::-webkit-scrollbar-thumb { background: #ffffff22; border-radius: 8px; }
        .cg-opt { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid var(--border); }
        .cg-opt:last-child { border-bottom: none; }
        .cg-opt.active, .cg-opt:hover { background: #5b8cff1f; }
        .cg-opt .v { font-weight: 600; font-size: 13px; color: var(--text); word-break: break-all; }
        .cg-opt .meta { color: var(--muted); font-size: 11.5px; margin-top: 2px; font-family: var(--mono); word-break: break-all; }
        .cg-opt .cg-method { display: inline-block; font-family: var(--mono); font-size: 10px; font-weight: 700;
            padding: 1px 6px; border-radius: 4px; margin-right: 6px; background: #1f6feb22; color: #6ea8ff; vertical-align: middle; }
        .cg-empty { padding: 12px; color: var(--muted); font-size: 12.5px; text-align: center; }
        .cg-count { position: sticky; top: 0; background: var(--panel); color: var(--muted);
            font-size: 11px; padding: 6px 12px; border-bottom: 1px solid var(--border); }
    </style>
</head>
<body>
    <header class="top">
        <div class="wrap">
            <a href="{{ route('codeguardian.index') }}" class="brand"><span>◆ CodeGuardian</span></a>
            <a href="{{ route('codeguardian.index') }}" class="navlink">History</a>
            <a href="{{ route('codeguardian.insights') }}" class="navlink">Insights</a>
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
