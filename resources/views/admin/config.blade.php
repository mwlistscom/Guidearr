@extends('admin.layout')
@section('title', 'Config')
@section('content')
<h1>Config</h1>
<p class="muted" style="margin-bottom:1.2rem">Settings for the public serving endpoints. Stored in <code>storage/app/settings</code>, so they survive container restarts and don't require an <code>.env</code> edit.</p>

@if (session('status'))
    <div class="ok">{{ session('status') }}</div>
@endif

<form method="POST" action="{{ route('admin.settings.update') }}">
    @csrf
    @method('PUT')

    <div class="card">
        <h2>Playlist links</h2>
        <p class="muted">Public base URL the <strong>Links</strong> overlay (M3U / EPG / Stream) builds from. Running in Docker behind a reverse proxy, the app can't reliably detect its own public address &mdash; set it here. Use the <strong>site origin only</strong> (scheme, host, port &mdash; <em>no path</em>); the app appends <code>/m3u</code>, <code>/epg</code>, <code>/strm</code> and <code>?key=</code> itself.</p>
        @error('links_base_url')<p class="err">{{ $message }}</p>@enderror
        <input type="text" name="links_base_url" value="{{ old('links_base_url', $linksBaseUrl) }}"
               placeholder="https://guidearr.example.com:7979" class="fld mono">
        @if($linksBaseUrl)
            <p class="muted small">Example: <code>{{ $linksBaseUrl }}/m3u?key=&lt;playlist-key&gt;</code></p>
        @else
            <p class="muted small">Not set &mdash; the Links overlay will tell users it isn't configured yet.</p>
        @endif
    </div>

    <div class="card">
        <h2>Rate limit</h2>
        <p class="muted">Protects the public endpoints from abuse: a playlist answers at most <em>N</em> distinct client IPs within a rolling window. IP-locked playlists bypass this. Requests over the cap get a "Too Many Devices" placeholder instead of the real data.</p>
        <div class="row">
            <label>Max unique IPs per playlist
                @error('serve_max_ips')<span class="err">{{ $message }}</span>@enderror
                <input type="number" name="serve_max_ips" min="1" max="100000" value="{{ old('serve_max_ips', $serveMaxIps) }}" class="fld">
            </label>
            <label>Window (hours)
                @error('serve_window_hours')<span class="err">{{ $message }}</span>@enderror
                <input type="number" name="serve_window_hours" min="1" max="168" value="{{ old('serve_window_hours', $serveWindowHours) }}" class="fld">
            </label>
        </div>
    </div>

    <button type="submit" class="save">Save configuration</button>
</form>

<style>
    .card { background:#16171a; border:1px solid rgba(255,255,255,.10); border-radius:.6rem; padding:1.1rem 1.2rem; max-width:48rem; margin-bottom:1.1rem; }
    .card h2 { font-size:1.05rem; margin:0 0 .4rem; }
    .muted { color:var(--muted); line-height:1.5; }
    .muted.small { font-size:.82rem; margin-top:.55rem; }
    .muted code, .card code { background:rgba(255,255,255,.08); padding:.05rem .3rem; border-radius:.25rem; }
    .fld { padding:.5rem .6rem; border-radius:.5rem; border:1px solid rgba(255,255,255,.18); background:#0f1012; color:#e6e7ea; margin:.4rem 0 0; }
    .fld.mono { width:100%; font-family:ui-monospace,monospace; }
    .row { display:flex; gap:1.4rem; flex-wrap:wrap; }
    .row label { display:flex; flex-direction:column; font-size:.85rem; color:#cdd2da; }
    .row .fld { width:11rem; }
    .err { color:#f87171; font-size:.82rem; display:block; margin:.2rem 0; }
    .save { background:var(--accent); color:#1a1205; border:none; font-weight:700; border-radius:.5rem; padding:.55rem 1.1rem; cursor:pointer; }
    .ok { background:rgba(110,231,183,.12); border:1px solid rgba(110,231,183,.4); color:#6ee7b7; padding:.6rem .8rem; border-radius:.5rem; margin-bottom:1rem; max-width:48rem; }
</style>
@endsection
