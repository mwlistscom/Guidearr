@extends('admin.layout')
@section('title', 'Feeds · ' . $provider->name)
@section('content')
<meta name="csrf-token" content="{{ csrf_token() }}">
<link href="https://cdnjs.cloudflare.com/ajax/libs/tabulator/6.3.1/css/tabulator_midnight.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/tabulator/6.3.1/js/tabulator.min.js"></script>

<div class="crumb">
    <a href="{{ route('admin.feeds') }}">Feeds</a> /
    <a href="{{ route('admin.feeds.user', $provider->user) }}">{{ $provider->user->name ?? 'user' }}</a> /
    {{ $provider->name }}
</div>
<h1>{{ $provider->name }} — channels</h1>

<div style="display:flex;gap:.6rem;align-items:center;margin:.5rem 0 1rem">
    <input id="af-search" placeholder="Filter name / group / tvg-name…"
        style="flex:1;max-width:24rem;background:#0e0f13;border:1px solid rgba(255,255,255,.16);color:#e6e7ea;border-radius:.5rem;padding:.45rem .6rem">
    <span id="af-count" style="color:var(--muted);font-size:.85rem"></span>
</div>

<div class="af-split">
    <div class="af-ch"><div id="af-grid"></div></div>
    <div class="af-gr">
        <div class="af-gr-title">Groups</div>
        <div id="af-groups"></div>
    </div>
</div>

<style>
    .af-split { display:flex; gap:1rem; align-items:flex-start; }
    .af-ch { flex:3; min-width:0; } .af-gr { flex:1; min-width:0; }
    .af-split .tabulator { background:#16171a; border:1px solid rgba(255,255,255,.10); font-size:.82rem; }
    .af-split .tabulator .tabulator-header { background:#1c1d21; }
    .af-split .tabulator-row .tabulator-cell { padding:3px 8px; }
    .af-gr-title { background:#1c1d21; border:1px solid rgba(255,255,255,.10); border-bottom:none;
        border-radius:.5rem .5rem 0 0; padding:.45rem .7rem; font-size:.8rem; font-weight:700; color:#cbd; }
    .af-gr .tabulator { border-radius:0 0 .5rem .5rem; }
    .af-gr .tabulator-row { cursor:pointer; }
    .af-gr .tabulator-row.tabulator-selected { background:rgba(244,117,33,.18) !important; }
    .crumb { color:var(--muted); margin-bottom:1rem; font-size:.9rem; }
    .crumb a { color:var(--accent); text-decoration:none; }
    .af-logo { height:22px; max-width:44px; object-fit:contain; vertical-align:middle; }
    .af-del { background:transparent;border:none;color:#aab;cursor:pointer;padding:.2rem;line-height:0;border-radius:.35rem }
    .af-del:hover { color:#f87171;background:rgba(248,113,113,.12) }
    .af-del svg { width:15px;height:15px }
</style>

<script>
(function () {
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const base = '{{ route('admin.feeds.provider', $provider) }}';
    let groupFilter = null, gtable = null;
    const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[m]));
    const J = async (url, method, body) => {
        const r = await fetch(url, { method, headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : null });
        let d = {}; try { d = await r.json(); } catch (e) {} return { ok: r.ok, data: d };
    };
    const del = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';

    const save = async (cell) => {
        const id = cell.getRow().getData().id;
        const { ok, data } = await J(base + '/channels/' + id, 'PATCH', { field: cell.getField(), value: cell.getValue() });
        if (!ok) { cell.restoreOldValue(); alert(data.message || 'Could not save.'); }
    };

    const table = new Tabulator('#af-grid', {
        layout: 'fitColumns', height: '64vh', editTriggerEvent: 'dblclick',
        placeholder: 'No channels — this provider has not been processed.',
        pagination: true, paginationMode: 'remote', paginationSize: 75,
        ajaxURL: '{{ route('admin.feeds.provider.data', $provider) }}',
        ajaxParams: () => ({ search: document.getElementById('af-search').value || '', group: groupFilter || '' }),
        ajaxResponse: (u, p, res) => {
            const c = document.getElementById('af-count');
            if (res.error) { c.innerHTML = '<span style="color:#f87171">Error: ' + esc(res.error) + '</span>'; }
            else { c.textContent = (res.total ?? 0) + ' channels'; }
            return res;
        },
        columns: [
            { title: 'Logo', field: 'tvg_logo', width: 58, hozAlign: 'center', headerSort: false,
              formatter: c => c.getValue() ? `<img class="af-logo" src="${esc(c.getValue())}" onerror="this.style.display='none'">` : '' },
            { title: 'Name', field: 'name', widthGrow: 2, editor: 'input', cellEdited: save },
            { title: 'tvg-name', field: 'tvg_name', widthGrow: 2, editor: 'input', cellEdited: save },
            { title: 'Group', field: 'group_title', widthGrow: 1, editor: 'input', cellEdited: save },
            { title: 'Type', field: 'type', width: 78, editor: 'list', editorParams: { values: ['Live', 'VOD', 'user'] }, cellEdited: save },
            { title: 'URL', field: 'url', widthGrow: 3, editor: 'input', cellEdited: save },
            { title: '', field: '_d', width: 46, hozAlign: 'center', headerSort: false,
              formatter: () => `<button class="af-del" title="Delete">${del}</button>`,
              cellClick: async (e, c) => { if (!e.target.closest('button')) return;
                  if (!confirm('Delete this channel?')) return;
                  await J(base + '/channels/' + c.getRow().getData().id, 'DELETE');
                  table.replaceData(); } },
        ],
    });

    // groups pane — click to filter the channels grid (exact); click again to clear
    fetch('{{ route('admin.feeds.provider.groups', $provider) }}', { headers: { 'Accept': 'application/json' } })
        .then(r => r.json()).then(d => {
            gtable = new Tabulator('#af-groups', {
                layout: 'fitColumns', height: '64vh', data: (d && d.groups) || [], selectableRows: 1,
                placeholder: 'No groups.',
                columns: [
                    { title: 'Group', field: 'group_title', widthGrow: 3 },
                    { title: 'Ch', field: 'channels', width: 50, hozAlign: 'right' },
                ],
            });
            gtable.on('rowClick', (e, row) => {
                const t = row.getData().group_title;
                if (groupFilter === t) { groupFilter = null; gtable.deselectRow(); }
                else { groupFilter = t; gtable.deselectRow(); row.select(); }
                table.setPage(1);
            });
        });

    let t = null;
    document.getElementById('af-search').addEventListener('input', () => {
        clearTimeout(t); t = setTimeout(() => table.setPage(1), 300);
    });
})();
</script>
@endsection
