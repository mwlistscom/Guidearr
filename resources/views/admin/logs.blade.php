@extends('admin.layout')
@section('title', 'Logs')
@section('content')
<meta name="csrf-token" content="{{ csrf_token() }}">
<h1>Logs</h1>
<p class="muted">Application logs from <code>storage/logs</code>. The newest entries are at the bottom.</p>

<div class="logbar">
    <select id="lg-file">
        @forelse ($files as $f)
            <option value="{{ $f['name'] }}">{{ $f['name'] }} ({{ number_format($f['size'] / 1024, 1) }} KB)</option>
        @empty
            <option value="">No log files</option>
        @endforelse
    </select>
    <select id="lg-lines">
        <option value="200">Last 200 lines</option>
        <option value="500" selected>Last 500 lines</option>
        <option value="1000">Last 1000 lines</option>
        <option value="5000">Last 5000 lines</option>
    </select>
    <input id="lg-filter" placeholder="Filter shown lines…" autocomplete="off">
    <button id="lg-refresh" type="button">Refresh</button>
    <span class="spacer"></span>
    <a class="dl" href="{{ route('admin.logs.bundle') }}">Download log bundle (.tar.gz)</a>
</div>

<pre id="lg-view" aria-live="polite">Select a log file…</pre>

<style>
    .muted { color:var(--muted); margin-bottom:1rem; }
    .muted code { background:rgba(255,255,255,.08); padding:.05rem .3rem; border-radius:.25rem; }
    .logbar { display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; margin-bottom:.8rem; }
    .logbar select, .logbar input, .logbar button {
        background:#0e0f13; border:1px solid rgba(255,255,255,.18); color:#e6e7ea; border-radius:.45rem; padding:.4rem .6rem; }
    .logbar input { flex:1; min-width:10rem; }
    .logbar button { cursor:pointer; }
    .logbar .spacer { flex:1; }
    .logbar .dl { background:var(--accent); color:#1a1205; font-weight:700; border:none; border-radius:.45rem;
        padding:.45rem .8rem; text-decoration:none; white-space:nowrap; }
    .logbar .dl:hover { filter:brightness(1.1); }
    #lg-view { background:#0c0d10; border:1px solid rgba(255,255,255,.10); border-radius:.6rem;
        padding:1rem 1.1rem; height:66vh; overflow:auto; white-space:pre-wrap; word-break:break-word;
        font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; line-height:1.5; color:#cdd2da; }
    #lg-view .lvl-error { color:#f87171; }
    #lg-view .lvl-warn  { color:#fbbf24; }
    #lg-view .lvl-info  { color:#8ab4f8; }
    #lg-view .hide { display:none; }
</style>

<script>
(function () {
    console.log('admin logs {{ config('guidearr.version') }} loaded');
    const $ = id => document.getElementById(id);
    const view = $('lg-view');
    const esc = s => String(s ?? '').replace(/[&<>]/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;' }[m]));

    function classify(line) {
        if (/\.ERROR\b|\bERROR:/.test(line) || /Exception|Stack trace|#\d+ /.test(line)) return 'lvl-error';
        if (/\.WARNING\b|\bWARNING:/.test(line)) return 'lvl-warn';
        if (/\.INFO\b|\bINFO:/.test(line)) return 'lvl-info';
        return '';
    }

    async function load() {
        const file = $('lg-file').value;
        if (!file) { view.textContent = 'No log files.'; return; }
        view.textContent = 'Loading…';
        try {
            const r = await fetch('{{ route('admin.logs.view') }}?file=' + encodeURIComponent(file) + '&lines=' + $('lg-lines').value,
                { headers: { 'Accept': 'application/json' } });
            const d = await r.json();
            if (!r.ok) { view.textContent = d.error || 'Could not load log.'; return; }
            render(d.text || '');
        } catch (e) { view.textContent = 'Error loading log.'; }
    }

    function render(text) {
        const rows = text.split('\n');
        view.innerHTML = rows.map(l => {
            const c = classify(l);
            return '<div class="logline ' + c + '">' + (esc(l) || '&nbsp;') + '</div>';
        }).join('');
        applyFilter();
        view.scrollTop = view.scrollHeight; // newest at the bottom
    }

    function applyFilter() {
        const q = $('lg-filter').value.toLowerCase();
        view.querySelectorAll('.logline').forEach(el => {
            el.classList.toggle('hide', q !== '' && !el.textContent.toLowerCase().includes(q));
        });
    }

    $('lg-file').addEventListener('change', load);
    $('lg-lines').addEventListener('change', load);
    $('lg-refresh').addEventListener('click', load);
    let t = null;
    $('lg-filter').addEventListener('input', () => { clearTimeout(t); t = setTimeout(applyFilter, 200); });

    load();
})();
</script>
@endsection
