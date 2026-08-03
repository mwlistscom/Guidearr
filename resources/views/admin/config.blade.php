@extends('admin.layout')
@section('title', 'Config')
@section('content')
<h1>Config</h1>
<p class="muted" style="margin-bottom:1.2rem">Application settings for the public serving endpoints and background workers. Stored in <code>storage/app/settings</code>, so they survive container restarts and don't require an <code>.env</code> edit.</p>

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

    <div class="card">
        <h2>Background workers</h2>
        <p class="muted">Maximum number of feed workers to run at once. The supervisor starts extra workers only when providers are queued and stops them as the backlog drains, up to this limit. <strong>1</strong> keeps the classic single worker; raise it to refresh many providers in parallel &mdash; but only as high as the box's spare CPU and memory allow (each worker downloads and parses a feed independently). Takes effect within a few seconds; no restart needed.</p>
        <div class="row">
            <label>Worker limit
                @error('worker_limit')<span class="err">{{ $message }}</span>@enderror
                <input type="number" name="worker_limit" min="1" max="16" value="{{ old('worker_limit', $workerLimit) }}" class="fld">
            </label>
        </div>
    </div>

    <div class="card">
        <h2>Threat feed</h2>
        <p class="muted">Publishes a plain-text list of IP addresses caught probing this install &mdash; scanners asking for <code>/.env</code>, <code>/wp-login.php</code> and the like &mdash; for a firewall to block at the edge. Point <strong>pfBlockerNG</strong> (or any tool that reads a URL list) at the address below as a custom IPv4 source. Hosts that have successfully pulled a playlist are never listed, and neither is private or reserved address space.</p>

        <label class="check">
            <input type="checkbox" name="threat_feed_enabled" value="1" @checked(old('threat_feed_enabled', $threatFeedEnabled))>
            <span>Serve the feed</span>
        </label>

        <div class="row" style="margin-top:.8rem">
            <label style="flex:1 1 26rem">Feed URL &mdash; give this address to pfBlockerNG
                @error('threat_feed_slug')<span class="err">{{ $message }}</span>@enderror
                <span class="urlbuild">
                    <span class="prefix">{{ $threatFeedBase }}/security/threat-feed/</span>
                    <input type="text" name="threat_feed_slug" value="{{ old('threat_feed_slug', $threatFeedSlug) }}" class="fld mono" spellcheck="false">
                    {{-- pfBlockerNG infers a list's format from the URL and wants an extension.
                         The route ignores it, so the address works with or without. --}}
                    <span class="prefix">.txt</span>
                </span>
            </label>
            <label>List after N attacks
                @error('threat_feed_min_hits')<span class="err">{{ $message }}</span>@enderror
                <input type="number" name="threat_feed_min_hits" min="1" max="10000" value="{{ old('threat_feed_min_hits', $threatFeedMinHits) }}" class="fld">
            </label>
        </div>

        <p class="muted small copyrow">
            <code id="tfUrl">{{ $threatFeedUrl }}</code>
            {{-- type="button": this sits inside the settings form and must not submit it. --}}
            <button type="button" class="copybtn" data-copy="tfUrl" aria-label="Copy the feed URL">Copy</button>
        </p>
        <p class="muted small">
            Only the middle part is editable &mdash; it's the secret, and one was generated for you.
            The <code>.txt</code> is there because <strong>pfBlockerNG</strong> wants a file
            extension; the address works with or without it. Change it to anything you like (letters, numbers, dot, dash, underscore or tilde; 8 characters or more). A wrong one returns 404, so the address can't be found by guessing.
            <strong>List after N attacks</strong> is how many hostile requests one address must make before it appears &mdash; lower lists more, sooner.
            @if ($threatFeedGeneratedAt)
                Currently listing <strong>{{ $threatFeedCount }}</strong> address(es); rebuilt {{ $threatFeedGeneratedAt }}.
            @else
                Not built yet &mdash; it is generated on the first fetch, then refreshed hourly.
            @endif
        </p>
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
    .check { display:flex; align-items:center; gap:.5rem; font-size:.9rem; color:#cdd2da; cursor:pointer; }
    .check input { width:auto; margin:0; }
    /* Reads as one address: fixed origin, editable secret. */
    .urlbuild { display:flex; align-items:center; flex-wrap:wrap; gap:.15rem; margin-top:.4rem; }
    .urlbuild .prefix { font-family:ui-monospace,monospace; font-size:.8rem; color:var(--muted); white-space:nowrap; }
    .urlbuild .fld { margin:0; flex:1 1 12rem; min-width:10rem; }
    .copyrow { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
    .copyrow code { word-break:break-all; }
    .copybtn { flex:none; cursor:pointer; font:inherit; font-size:.78rem; padding:.2rem .6rem;
               border-radius:.35rem; border:1px solid rgba(255,255,255,.22); background:#0f1012; color:#e6e7ea; }
    .copybtn:hover { border-color:rgba(255,255,255,.4); }
    .copybtn.done { border-color:rgba(110,231,183,.5); color:#6ee7b7; }
    .row .fld { width:11rem; }
    .err { color:#f87171; font-size:.82rem; display:block; margin:.2rem 0; }
    .save { background:var(--accent); color:#1a1205; border:none; font-weight:700; border-radius:.5rem; padding:.55rem 1.1rem; cursor:pointer; }
    .ok { background:rgba(110,231,183,.12); border:1px solid rgba(110,231,183,.4); color:#6ee7b7; padding:.6rem .8rem; border-radius:.5rem; margin-bottom:1rem; max-width:48rem; }
</style>

<script>
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        var source = document.getElementById(btn.getAttribute('data-copy'));
        if (!source) { return; }

        function flash(label, ok) {
            btn.textContent = label;
            btn.classList.toggle('done', ok);
            setTimeout(function () {
                btn.textContent = 'Copy';
                btn.classList.remove('done');
            }, 1600);
        }

        // navigator.clipboard exists only in a secure context. The admin panel is
        // normally HTTPS, but the stack also publishes a plain-HTTP port for a local
        // reverse proxy — so fall back to a throwaway textarea + execCommand there
        // rather than leaving the button dead.
        function legacyCopy(text) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();

            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(ta);

            return ok;
        }

        function fallback(text) {
            var ok = legacyCopy(text);
            flash(ok ? 'Copied' : 'Press Ctrl+C', ok);
        }

        btn.addEventListener('click', function () {
            var text = source.textContent.trim();

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(
                    function () { flash('Copied', true); },
                    function () { fallback(text); }
                );

                return;
            }

            fallback(text);
        });
    });
</script>
@endsection
