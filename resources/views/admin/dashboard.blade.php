@extends('admin.layout')
@section('title', 'Status')
@section('content')
<h1>Status</h1>
<div class="stats">
    <div class="stat"><span class="n">{{ $userCount }}</span><span class="l">Users</span></div>
    <div class="stat"><span class="n">{{ $pending }}</span><span class="l">Pending</span></div>
    <div class="stat"><span class="n">{{ $banned }}</span><span class="l">Banned</span></div>
    <div class="stat"><span class="n">{{ config('guidearr.version') }}</span><span class="l">Version</span></div>
</div>
<div class="grid">
    <a class="tile" href="{{ route('admin.users') }}"><h3>Users</h3><p>Authorize, ban, or delete accounts.</p></a>
    <a class="tile" href="{{ route('admin.environment') }}"><h3>Environment</h3><p>Edit .env variables safely.</p></a>
    <a class="tile" href="{{ route('admin.branding') }}"><h3>Branding</h3><p>Upload and manage the app icon.</p></a>
</div>

<h2 style="margin-top:2rem">Playlist links</h2>
<div class="card" style="max-width:46rem">
    <p class="muted">Public base URL that the <strong>Links</strong> overlay (M3U / EPG / Stream) builds playlist links from. Because the app runs in Docker behind a reverse proxy, it can't reliably detect its own public address &mdash; set it here. No trailing slash; the endpoint filenames (<code>/m3u.php</code>, <code>/tvg.php</code>, <code>/strm.php</code>) and <code>?key=</code> are appended automatically.</p>
    @error('links_base_url')<p style="color:#f87171;margin:.2rem 0">{{ $message }}</p>@enderror
    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf
        @method('PUT')
        <input type="text" name="links_base_url" value="{{ old('links_base_url', $linksBaseUrl) }}"
               placeholder="https://m3u.mwlists.com/m3u"
               style="width:100%;padding:.5rem .6rem;border-radius:.5rem;border:1px solid rgba(255,255,255,.18);background:#16171a;color:#e6e7ea;font-family:ui-monospace,monospace;margin:.3rem 0 .6rem">
        <button type="submit">Save</button>
    </form>
    @if($linksBaseUrl)
        <p class="muted" style="margin-top:.7rem">Example: <code>{{ $linksBaseUrl }}/m3u.php?key=&lt;playlist-key&gt;</code></p>
    @else
        <p class="muted" style="margin-top:.7rem">Not set &mdash; the Links overlay will tell users it isn't configured yet.</p>
    @endif
</div>

<h2 style="margin-top:2rem">Maintenance</h2>
<div class="card" style="max-width:34rem">
    <p class="muted">Clear all caches and gracefully reload the application workers so changes to environment variables (database, mail, app settings) take effect. This reloads the app only — it does not restart the database, web, or mail containers.</p>
    <form method="POST" action="{{ route('admin.restart') }}"
          onsubmit="return confirm('Reload application services now?')">
        @csrf
        <button type="submit">Reload services</button>
    </form>
</div>
@endsection
