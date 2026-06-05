@extends('admin.layout')
@section('title', 'Status')
@section('content')
@php
    $human = function ($b) {
        $b = (float) $b;
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
        return ($i === 0 ? (int) $b : number_format($b, 1)) . ' ' . $u[$i];
    };
@endphp
<h1>Status</h1>

@if (!empty($update) && $update['available'])
<div class="upd-alert" onclick="document.getElementById('upd-modal').style.display='flex'" role="button" tabindex="0"
     onkeypress="if(event.key==='Enter'){document.getElementById('upd-modal').style.display='flex'}">
    <span class="upd-dot"></span>
    <span><strong>Update available — v{{ $update['latest'] }}.</strong> You're on v{{ $update['current'] }}. Click for update instructions.</span>
    <span class="upd-go">→</span>
</div>
@endif
<div class="stats">
    <div class="stat"><span class="n">{{ $userCount }}</span><span class="l">Users</span></div>
    <div class="stat"><span class="n">{{ $pending }}</span><span class="l">Pending</span></div>
    <div class="stat"><span class="n">{{ $banned }}</span><span class="l">Banned</span></div>
    <div class="stat"><span class="n">{{ config('guidearr.version') }}</span><span class="l">Version</span></div>
</div>

<h2 style="margin-top:1.6rem">System</h2>
<div class="sys">
    <div class="card">
        <div class="sys-head"><span>Disk</span><span class="muted">{{ $human($sys['disk']['used']) }} / {{ $human($sys['disk']['total']) }} &middot; {{ $human($sys['disk']['free']) }} free</span></div>
        <div class="bar"><i class="{{ $sys['disk']['pct'] >= 90 ? 'hot' : ($sys['disk']['pct'] >= 75 ? 'warn' : '') }}" style="width:{{ $sys['disk']['pct'] }}%"></i></div>
        <div class="sys-foot">{{ $sys['disk']['pct'] }}% used</div>
    </div>
    <div class="card">
        <div class="sys-head"><span>Memory</span><span class="muted">@if($sys['mem']){{ $human($sys['mem']['used']) }} / {{ $human($sys['mem']['total']) }}@else unavailable @endif</span></div>
        <div class="bar"><i class="{{ ($sys['mem']['pct'] ?? 0) >= 90 ? 'hot' : (($sys['mem']['pct'] ?? 0) >= 75 ? 'warn' : '') }}" style="width:{{ $sys['mem']['pct'] ?? 0 }}%"></i></div>
        <div class="sys-foot">@if($sys['mem']){{ $sys['mem']['pct'] }}% used @else — @endif</div>
    </div>
    <div class="card">
        <div class="sys-head"><span>CPU load</span><span class="muted">@if($sys['cores']){{ $sys['cores'] }} cores @endif</span></div>
        <div class="sys-big">@if($sys['load']){{ number_format($sys['load'][0], 2) }}@else — @endif</div>
        <div class="sys-foot">@if($sys['load'])1m · {{ number_format($sys['load'][1], 2) }} 5m · {{ number_format($sys['load'][2], 2) }} 15m @else load unavailable @endif</div>
    </div>
    <div class="card">
        <div class="sys-head"><span>Data stores</span><span class="muted">{{ $sys['stores']['files'] }} SQLite files</span></div>
        <div class="sys-big">{{ $human($sys['stores']['bytes']) }}</div>
        <div class="sys-foot">{{ $sys['counts']['providers'] }} providers · {{ $sys['counts']['playlists'] }} playlists</div>
    </div>
</div>

<div class="grid">
    <a class="tile" href="{{ route('admin.users') }}"><h3>Users</h3><p>Authorize, ban, or delete accounts.</p></a>
    <a class="tile" href="{{ route('admin.feeds') }}"><h3>Feeds</h3><p>Browse providers and playlists.</p></a>
    <a class="tile" href="{{ route('admin.config') }}"><h3>Config</h3><p>Serving links and rate limits.</p></a>
    <a class="tile" href="{{ route('admin.environment') }}"><h3>Environment</h3><p>Edit .env variables safely.</p></a>
    <a class="tile" href="{{ route('admin.branding') }}"><h3>Branding</h3><p>Upload and manage the app icon.</p></a>
    <a class="tile" href="{{ route('admin.maintenance') }}"><h3>Maintenance</h3><p>Prune unused playlists by activity.</p></a>
</div>

<style>
    .sys { display:grid; grid-template-columns:repeat(auto-fit,minmax(15rem,1fr)); gap:1rem; margin-bottom:.5rem; }
    .sys .card { padding:1rem 1.1rem; }
    .sys .muted { color:var(--muted); font-size:.78rem; }
    .sys-head { display:flex; justify-content:space-between; align-items:baseline; font-weight:600; margin-bottom:.55rem; }
    .sys-foot { color:var(--muted); font-size:.75rem; margin-top:.45rem; }
    .sys-big { font-size:1.7rem; font-weight:700; line-height:1.1; }
    .bar { height:.55rem; border-radius:.3rem; background:rgba(255,255,255,.10); overflow:hidden; }
    .bar i { display:block; height:100%; background:var(--accent); border-radius:.3rem; }
    .bar i.warn { background:#fbbf24; }
    .bar i.hot  { background:#f87171; }
</style>

<h2 style="margin-top:2rem">Services</h2>
<div class="card" style="max-width:34rem">
    <p class="muted">Clear all caches and gracefully reload the application workers so changes to environment variables (database, mail, app settings) take effect. This reloads the app only — it does not restart the database, web, or mail containers.</p>
    <form method="POST" action="{{ route('admin.restart') }}"
          onsubmit="return confirm('Reload application services now?')">
        @csrf
        <button type="submit">Reload services</button>
    </form>
</div>
@if (!empty($update) && $update['available'])
<div id="upd-modal" class="upd-modal" onclick="if(event.target===this)this.style.display='none'">
    <div class="upd-box">
        <div class="upd-head">
            <h2>Update to v{{ $update['latest'] }}</h2>
            <button type="button" class="upd-x" onclick="document.getElementById('upd-modal').style.display='none'">&times;</button>
        </div>
        <p class="muted">You're running v{{ $update['current'] }}. Update from your Guidearr directory:</p>
        <pre class="upd-code">cd /opt/Guidearr        # your install directory
git pull                # or download the latest release from GitHub
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear</pre>
        <p class="muted">The worker and scheduler restart automatically on rebuild. Review what changed before updating:</p>
        <p><a class="upd-link" href="{{ $update['url'] }}" target="_blank" rel="noopener">View release notes on GitHub →</a></p>
    </div>
</div>
@endif

<style>
    .upd-alert { display:flex; align-items:center; gap:.7rem; cursor:pointer; margin:0 0 1.4rem;
        background:rgba(34,197,94,.12); border:1px solid rgba(34,197,94,.45); color:#bbf7d0;
        border-radius:.6rem; padding:.7rem 1rem; font-size:.95rem; }
    .upd-alert:hover { background:rgba(34,197,94,.18); }
    .upd-alert .upd-dot { width:.6rem; height:.6rem; border-radius:50%; background:#22c55e; flex:0 0 auto;
        box-shadow:0 0 0 0 rgba(34,197,94,.7); animation:upd-pulse 2s infinite; }
    .upd-alert .upd-go { margin-left:auto; font-weight:700; }
    @keyframes upd-pulse { 0%{box-shadow:0 0 0 0 rgba(34,197,94,.6)} 70%{box-shadow:0 0 0 .5rem rgba(34,197,94,0)} 100%{box-shadow:0 0 0 0 rgba(34,197,94,0)} }
    .upd-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:1000;
        align-items:center; justify-content:center; padding:1rem; }
    .upd-box { background:#16181d; border:1px solid rgba(255,255,255,.14); border-radius:.7rem;
        max-width:40rem; width:100%; padding:1.3rem 1.5rem; }
    .upd-head { display:flex; align-items:center; justify-content:space-between; }
    .upd-head h2 { margin:0; font-size:1.3rem; }
    .upd-x { background:none; border:none; color:var(--muted); font-size:1.6rem; line-height:1; cursor:pointer; padding:0 .2rem; }
    .upd-x:hover { color:#fff; }
    .upd-code { background:#0c0d10; border:1px solid rgba(255,255,255,.10); border-radius:.5rem;
        padding:.9rem 1rem; overflow:auto; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:.82rem;
        line-height:1.6; color:#cdd2da; white-space:pre; }
    .upd-link { color:var(--accent); text-decoration:none; font-weight:600; }
    .upd-link:hover { text-decoration:underline; }
</style>
@endsection
