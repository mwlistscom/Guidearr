@extends('admin.layout')
@section('title', 'Feeds · ' . $user->name)
@section('content')
<div class="crumb"><a href="{{ route('admin.feeds') }}">Feeds</a> / {{ $user->name }}</div>
<h1>{{ $user->name }}'s providers</h1>

<table class="dtbl">
    <thead><tr><th>Provider</th><th style="width:7rem">Type</th><th style="width:6rem">Enabled</th><th style="width:8rem">Channels</th><th style="width:12rem">Last Refresh</th><th style="width:7rem"></th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
        <tr>
            <td>{{ $r['provider']->name }}</td>
            <td>{{ strtoupper($r['provider']->type) }}</td>
            <td>{{ $r['provider']->enabled ? 'Yes' : 'No' }}</td>
            <td>{{ number_format($r['channels']) }}</td>
            <td style="color:var(--muted)">{{ optional($r['provider']->last_refresh_at)->format('Y-m-d H:i') ?? '—' }}</td>
            <td><a class="btn accent" href="{{ route('admin.feeds.provider', $r['provider']) }}">Browse</a></td>
        </tr>
    @empty
        <tr><td colspan="6" style="color:var(--muted)">This user has no providers.</td></tr>
    @endforelse
    </tbody>
</table>

<h2 class="sec">{{ $user->name }}'s playlists</h2>
<table class="dtbl">
    <thead><tr><th>Playlist</th><th style="width:8rem">Channels</th><th style="width:7rem">Groups</th><th style="width:6rem">Enabled</th><th style="width:7rem"></th></tr></thead>
    <tbody>
    @forelse ($playlists as $r)
        <tr>
            <td>{{ $r['playlist']->name }}</td>
            <td>{{ number_format($r['channels']) }}</td>
            <td>{{ number_format($r['groups']) }}</td>
            <td>{{ $r['playlist']->enabled ? 'Yes' : 'No' }}</td>
            <td><a class="btn accent" href="{{ route('admin.feeds.playlist', $r['playlist']) }}">Browse</a></td>
        </tr>
    @empty
        <tr><td colspan="5" style="color:var(--muted)">This user has no playlists.</td></tr>
    @endforelse
    </tbody>
</table>

<style>
    .sec { font-size:1.05rem; font-weight:700; margin:1.8rem 0 .5rem; }
    table.dtbl { width:100%; border-collapse:collapse; font-size:.9rem; }
    table.dtbl th, table.dtbl td { text-align:left; padding:.55rem .7rem; border-bottom:1px solid rgba(255,255,255,.08); }
    table.dtbl th { color:var(--muted); font-weight:600; }
    .btn { display:inline-block; background:#26272b; color:#e6e7ea; border:1px solid rgba(255,255,255,.14);
        border-radius:.45rem; padding:.3rem .7rem; font-size:.82rem; text-decoration:none; }
    .btn.accent { background:var(--accent); color:#1a1205; border:none; font-weight:700; }
    .btn:hover { filter:brightness(1.1); }
    .crumb { color:var(--muted); margin-bottom:1rem; font-size:.9rem; }
    .crumb a { color:var(--accent); text-decoration:none; }
</style>
@endsection
