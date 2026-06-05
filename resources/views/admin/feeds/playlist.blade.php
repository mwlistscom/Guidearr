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
<h1>{{ $playlist->name }} — channels</h1>

<div style="display:flex;gap:.6rem;align-items:center;margin:.5rem 0 .4rem">
    <input id="pl-search" class="search" placeholder="Filter name / group / tvg-id…">
    <span id="pl-count" style="color:var(--muted);font-size:.85rem"></span>
</div>
<p class="muted small">Read-only. Disabled/deleted rows are dimmed. Click a group on the right to filter; click it again to clear.</p>

<div class="pl-split">
    <div class="pl-ch"><div id="pl-grid"></div></div>
    <div class="pl-gr">
        <div class="pl-gr-title">Groups</div>
        <div id="pl-groups"></div>
    </div>
</div>

<style>
    .muted { color:var(--muted); } .muted.small { font-size:.82rem; margin:0 0 .7rem; }
    .crumb { color:var(--muted); margin-bottom:1rem; font-size:.9rem; }
    .crumb a { color:var(--accent); text-decoration:none; }
    .search { flex:1; max-width:24rem; padding:.45rem .6rem; border-radius:.5rem;
        border:1px solid rgba(255,255,255,.16); background:#0e0f13; color:#e6e7ea; }
    .pl-split { display:flex; gap:1rem; align-items:flex-start; }
    .pl-ch { flex:3; min-width:0; } .pl-gr { flex:1; min-width:0; }
    .pl-split .tabulator { background:#16171a; border:1px solid rgba(255,255,255,.10); font-size:.82rem; }
    .pl-split .tabulator .tabulator-header { background:#1c1d21; }
    .pl-split .tabulator-row .tabulator-cell { padding:3px 8px; }
    .pl-split .row-off .tabulator-cell { opacity:.45; }
    .pl-gr-title { background:#1c1d21; border:1px solid rgba(255,255,255,.10); border-bottom:none;
        border-radius:.5rem .5rem 0 0; padding:.45rem .7rem; font-size:.8rem; font-weight:700; color:#cbd; }
    .pl-gr .tabulator { border-radius:0 0 .5rem .5rem; }
    .pl-gr .tabulator-row { cursor:pointer; }
    .pl-gr .tabulator-row.tabulator-selected { background:rgba(244,117,33,.18) !important; }
</style>

<script>
(function () {
    const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[m]));
    let groupFilter = null, gtable = null, t = null;

    const table = new Tabulator('#pl-grid', {
        layout: 'fitColumns', height: '64vh', dataLoaderLoading: '',
        pagination: true, paginationMode: 'remote', paginationSize: 75,
        ajaxURL: '{{ route('admin.feeds.playlist.data', $playlist) }}',
        ajaxParams: () => ({ search: document.getElementById('pl-search').value || '', group: groupFilter || '' }),
        ajaxResponse: (u, p, r) => { document.getElementById('pl-count').textContent = (r.total ?? 0) + ' channels'; return r; },
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

    // groups pane — click to filter the channel grid (exact); click again to clear
    fetch('{{ route('admin.feeds.playlist.groups', $playlist) }}', { headers: { 'Accept': 'application/json' } })
        .then(r => r.json()).then(d => {
            gtable = new Tabulator('#pl-groups', {
                layout: 'fitColumns', height: '64vh', data: (d && d.groups) || [], selectableRows: 1,
                placeholder: 'No groups.',
                columns: [
                    { title: 'Group', field: 'group_title', widthGrow: 3 },
                    { title: 'Ch', field: 'channels', width: 50, hozAlign: 'right' },
                ],
            });
            gtable.on('rowClick', (e, row) => {
                const g = row.getData().group_title;
                if (groupFilter === g) { groupFilter = null; gtable.deselectRow(); }
                else { groupFilter = g; gtable.deselectRow(); row.select(); }
                table.setPage(1);
            });
        });

    document.getElementById('pl-search').addEventListener('input', () => {
        clearTimeout(t); t = setTimeout(() => table.setPage(1), 300);
    });
})();
</script>
@endsection
