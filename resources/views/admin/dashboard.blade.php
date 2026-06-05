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
@endsection
