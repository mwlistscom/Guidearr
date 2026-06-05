@extends('admin.layout')
@section('title', 'Playlist · ' . $playlist->name)
@section('content')
<meta name="csrf-token" content="{{ csrf_token() }}">
<link href="https://cdnjs.cloudflare.com/ajax/libs/tabulator/6.3.1/css/tabulator_midnight.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/tabulator/6.3.1/js/tabulator.min.js"></script>

<div class="crumb">
    <a href="{{ route('admin.feeds') }}">Feeds</a> /
    <a href="{{ route('admin.feeds.user', $playlist->user) }}">{{ $playlist->user->name ?? '—' }}</a> /
    {{ $playlist->name }}
</div>
<h1>{{ $playlist->name }}</h1>
<p class="muted">Read-only view of the channels this playlist serves (deleted/disabled rows shown dimmed). <span id="pl-total"></span></p>

<input id="pl-search" class="search" placeholder="Filter name, group, tvg-id…">
<div id="pl-grid"></div>

<style>
    .muted { color:var(--muted); margin-bottom:.8rem; }
    .crumb { color:var(--muted); margin-bottom:1rem; font-size:.9rem; }
    .crumb a { color:var(--accent); text-decoration:none; }
    .search { width:100%; max-width:26rem; padding:.5rem .6rem; border-radius:.5rem; margin-bottom:.7rem;
        border:1px solid rgba(255,255,255,.18); background:#0f1012; color:#e6e7ea; }
    #pl-grid .tabulator { background:#16171a; border:1px solid rgba(255,255,255,.10); font-size:.85rem; }
    #pl-grid .tabulator .tabulator-header { background:#1c1d21; }
    #pl-grid .row-off .tabulator-cell { opacity:.45; }
    .pl-logo { height:20px; max-width:40px; object-fit:contain; vertical-align:middle; }
</style>

<script>
(function () {
    const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[m]));
    let t = null, timer = null;

    function build() {
        if (t) { try { t.destroy(); } catch (e) {} }
        t = new Tabulator('#pl-grid', {
            layout: 'fitColumns', height: '64vh', dataLoaderLoading: '',
            pagination: true, paginationMode: 'remote', paginationSize: 50,
            ajaxURL: '{{ route('admin.feeds.playlist.data', $playlist) }}',
            ajaxParams: () => ({ search: document.getElementById('pl-search').value || '' }),
            ajaxResponse: (u, p, r) => { document.getElementById('pl-total').textContent = (r.total ?? 0) + ' channels.'; return r; },
            rowFormatter: row => { const d = row.getData(); if (!d.enabled || d.deleted) row.getElement().classList.add('row-off'); },
            placeholder: 'No channels.',
            columns: [
                { title: '#', field: 'row', width: 64, hozAlign: 'right', headerSort: false },
                { title: 'Name', field: 'name', widthGrow: 3 },
                { title: 'Group', field: 'group_title', widthGrow: 2 },
                { title: 'tvg-id', field: 'tvg_id', widthGrow: 2 },
                { title: 'URL', field: 'url', widthGrow: 4, formatter: c => `<span style="color:#8ab4f8">${esc(c.getValue())}</span>` },
            ],
        });
    }
    build();
    document.getElementById('pl-search').addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => t && t.setData(), 300);
    });
})();
</script>
@endsection
