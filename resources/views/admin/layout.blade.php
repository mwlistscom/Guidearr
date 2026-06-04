<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin') · {{ config('app.name','Guidearr') }}</title>
    <link rel="icon" href="/favicon.svg?v=2" type="image/svg+xml">
    @include('partials.turnstile')
    <style>
        :root { color-scheme: dark;
            --accent:#f47521; --bg:#0b0c0e; --panel:#16181d; --panel2:#1c1f26;
            --border:rgba(255,255,255,.07); --text:#e7e7ea; --muted:#9aa0a8; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background:var(--bg); color:var(--text); min-height:100svh; }
        a { color:inherit; text-decoration:none; }

        /* ---- sidebar shell (Prowlarr-style) ---- */
        .app { display:flex; min-height:100svh; }
        .sidebar { width:232px; flex-shrink:0; background:#101216; border-right:1px solid var(--border);
            display:flex; flex-direction:column; position:sticky; top:0; height:100svh; }
        .sidebar .brand { display:flex; align-items:center; gap:.6rem; padding:1.05rem 1.25rem;
            font-weight:800; letter-spacing:-.02em; color:#fff; border-bottom:1px solid var(--border);
            text-decoration:none; }
        a.sidebar-brand:hover { background:rgba(255,255,255,.03); }
        .sidebar .brand .logo { width:30px; height:30px; border-radius:7px; object-fit:contain;
            background:#0e0f13; padding:2px; border:1px solid var(--border); flex-shrink:0; }
        .sidebar nav { padding:.5rem 0; display:flex; flex-direction:column; }
        .sidebar nav a, .sidebar nav .disabled { display:flex; align-items:center; gap:.7rem;
            padding:.62rem 1.25rem; font-size:.92rem; color:var(--muted); border-left:3px solid transparent; }
        .sidebar nav a:hover { color:#fff; background:rgba(255,255,255,.03); }
        .sidebar nav a.active { color:#fff; background:rgba(244,117,33,.10); border-left-color:var(--accent); }
        .sidebar nav a.active svg { color:var(--accent); }
        .sidebar nav .disabled { opacity:.38; }
        .sidebar nav svg { width:18px; height:18px; flex-shrink:0; stroke-linecap:round; stroke-linejoin:round; }
        .sidebar .spacer { flex:1; }
        .sidebar .foot { padding:1rem 1.25rem; border-top:1px solid var(--border); }
        .sidebar .foot .ver { font-size:.72rem; color:var(--muted); margin-bottom:.5rem; letter-spacing:.02em; }
        .sidebar .foot .who { font-size:.78rem; color:var(--muted); margin-bottom:.6rem; word-break:break-all; }
        .sidebar .foot button { width:100%; }

        .content { flex:1; padding:2rem 2.25rem; max-width:72rem; }
        .content.centered { display:flex; align-items:center; justify-content:center; max-width:none; }

        h1 { font-size:1.5rem; font-weight:700; margin-bottom:1.25rem; letter-spacing:-.02em; }
        .muted { color:var(--muted); font-size:.92rem; margin-bottom:1rem; }

        .card { background:var(--panel); border:1px solid var(--border); border-radius:.9rem; padding:1.6rem; }
        .narrow { max-width:24rem; margin:0 auto; width:100%; }
        label { display:block; font-size:.85rem; color:#c4c4cc; margin:.9rem 0 .35rem; }
        input { width:100%; padding:.6rem .7rem; border-radius:.55rem; color:#fff;
            background:#0e0f13; border:1px solid rgba(255,255,255,.14); font-size:.95rem; }
        input:focus { outline:none; border-color:var(--accent); }
        button { cursor:pointer; padding:.55rem 1rem; border-radius:.55rem; border:1px solid transparent;
            background:var(--accent); color:#160a02; font-weight:700; font-size:.9rem; }
        button:hover { filter:brightness(1.07); }
        button.danger { background:transparent; color:#f87171; border-color:rgba(248,113,113,.4); font-weight:600; }
        button.ghost { background:transparent; color:var(--text); border-color:rgba(255,255,255,.16); font-weight:600; }
        .cf-turnstile { margin:1rem 0; }
        form.inline { display:inline; }

        .flash { background:rgba(52,211,153,.10); border:1px solid rgba(52,211,153,.3); color:#6ee7b7;
            padding:.7rem 1rem; border-radius:.6rem; margin-bottom:1.25rem; font-size:.9rem; }
        .error { background:rgba(248,113,113,.10); border:1px solid rgba(248,113,113,.3); color:#fca5a5;
            padding:.7rem 1rem; border-radius:.6rem; margin-bottom:1.25rem; font-size:.9rem; }
        .error ul { margin-left:1rem; }

        .stats { display:flex; gap:1rem; margin-bottom:1.75rem; flex-wrap:wrap; }
        .stat { background:var(--panel); border:1px solid var(--border); border-radius:.8rem; padding:1rem 1.4rem; min-width:7rem; }
        .stat .n { display:block; font-size:1.8rem; font-weight:800; color:#fff; }
        .stat .l { font-size:.8rem; color:var(--muted); }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(14rem,1fr)); gap:1rem; }
        .tile { display:block; background:var(--panel); border:1px solid var(--border); border-radius:.8rem; padding:1.2rem; }
        .tile:hover { border-color:rgba(244,117,33,.4); }
        .tile h3 { color:#fff; margin-bottom:.3rem; }
        .tile p { color:var(--muted); font-size:.88rem; }
        .tile.disabled { opacity:.45; } .tile.disabled:hover { border-color:var(--border); }

        table { width:100%; border-collapse:collapse; font-size:.9rem; }
        th, td { text-align:left; padding:.6rem .5rem; border-bottom:1px solid var(--border); }
        th { color:var(--muted); font-weight:600; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
        td.actions { display:flex; gap:.4rem; flex-wrap:wrap; }
        td.actions button { padding:.3rem .6rem; font-size:.8rem; }
        .badge { padding:.15rem .55rem; border-radius:999px; font-size:.75rem; }
        .badge.active { background:rgba(52,211,153,.15); color:#6ee7b7; }
        .badge.pending { background:rgba(251,191,36,.15); color:#fcd34d; }
        .badge.banned { background:rgba(248,113,113,.15); color:#fca5a5; }

        select { width:100%; padding:.6rem .7rem; border-radius:.55rem; color:#fff;
            background:#0e0f13; border:1px solid rgba(255,255,255,.14); font-size:.95rem; }
        select:focus { outline:none; border-color:var(--accent); }
        .filters { display:flex; gap:.6rem; margin-bottom:1rem; flex-wrap:wrap; }
        .filters input[type=search] { flex:1; min-width:14rem; }
        .filters select { width:auto; min-width:10rem; }
        .actions .icon { background:transparent; border:1px solid transparent; padding:.32rem; border-radius:.45rem;
            color:var(--muted); display:inline-flex; align-items:center; justify-content:center; cursor:pointer; }
        .actions .icon svg { width:16px; height:16px; stroke-linecap:round; stroke-linejoin:round; }
        .actions .icon:hover { color:#fff; background:rgba(255,255,255,.07); }
        .actions .icon.on:hover { color:#fca5a5; }
        .actions .icon.off:hover { color:#6ee7b7; }
        .actions .icon.danger:hover { color:#f87171; background:rgba(248,113,113,.10); }
        a.ghostbtn { display:inline-flex; align-items:center; padding:.55rem 1rem; border-radius:.55rem;
            border:1px solid rgba(255,255,255,.16); color:var(--text); font-weight:600; font-size:.9rem; }
        a.ghostbtn:hover { border-color:rgba(255,255,255,.34); }
        .empty { color:var(--muted); padding:1.2rem .5rem; font-size:.9rem; }
    </style>
</head>
<body>
@php($chrome = auth()->check() && auth()->user()?->is_admin && ! auth()->user()?->must_change_password)

@if ($chrome)
    <div class="app">
        <aside class="sidebar">
            <a class="brand sidebar-brand" href="{{ route('home') }}"><img class="logo" src="{{ route('branding.icon') }}" alt=""> {{ config('app.name','Guidearr') }}</a>
            <nav>
                <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    Status
                </a>
                <a href="{{ route('admin.users') }}" class="{{ request()->routeIs('admin.users') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Users
                </a>
                <a href="{{ route('admin.feeds') }}" class="{{ request()->routeIs('admin.feeds') || request()->routeIs('admin.feeds.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 9v12"/></svg>
                    Feeds
                </a>
                <a href="{{ route('admin.environment') }}" class="{{ request()->routeIs('admin.environment') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
                    Environment
                </a>
                <a href="{{ route('admin.branding') }}" class="{{ request()->routeIs('admin.branding') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    Branding
                </a>
            </nav>
            <div class="spacer"></div>
            <div class="foot">
                <div class="ver">{{ config('app.name','Guidearr') }} v{{ config('guidearr.version') }}</div>
                <div class="who">{{ auth()->user()->email }}</div>
                <form method="POST" action="{{ route('admin.logout') }}">@csrf<button class="ghost">Log out</button></form>
            </div>
        </aside>

        <main class="content">
            @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
            @if ($errors->any())<div class="error"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
    </div>
@else
    <main class="content centered">
        <div style="width:100%; max-width:24rem;">
            @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
            @if ($errors->any())<div class="error"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </div>
    </main>
@endif
</body>
</html>
