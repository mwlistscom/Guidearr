@extends('admin.layout')
@section('title', 'Feeds')
@section('content')
<meta name="csrf-token" content="{{ csrf_token() }}">
<link href="https://cdnjs.cloudflare.com/ajax/libs/tabulator/6.3.1/css/tabulator_midnight.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/tabulator/6.3.1/js/tabulator.min.js"></script>

<h1>Feeds</h1>
<p style="color:var(--muted);margin-bottom:1rem">The job queue and ingested provider data. Drill into a user to browse a provider's channels.</p>

<h2 class="sec">Job Queue</h2>
<p class="hint">Double-click a cell to edit. Type &amp; State are pulldowns. Deleting a row disables its provider.</p>
<div id="jq-grid"></div>

<h2 class="sec">Users</h2>
<table class="dtbl">
    <thead><tr><th>User</th><th>Email</th><th style="width:8rem">Providers</th><th style="width:6rem"></th></tr></thead>
    <tbody>
    @forelse ($users as $u)
        <tr>
            <td>{{ $u->name }}</td>
            <td style="color:var(--muted)">{{ $u->email }}</td>
            <td>{{ $u->providers_count }}</td>
            <td><a class="btn" href="{{ route('admin.feeds.user', $u) }}">View</a></td>
        </tr>
    @empty
        <tr><td colspan="4" style="color:var(--muted)">No users.</td></tr>
    @endforelse
    </tbody>
</table>

<h2 class="sec">Data Purge Queue</h2>
<p class="hint">Store-file cleanup for deleted accounts. Processed hourly by <code>feed:purge</code>.</p>
<table class="dtbl">
    <thead><tr><th>User</th><th style="width:7rem">Providers</th><th style="width:8rem">State</th><th style="width:6rem">Attempts</th><th style="width:12rem">Updated</th><th>Last error</th></tr></thead>
    <tbody>
    @forelse ($purges as $pg)
        <tr>
            <td style="color:var(--muted)">{{ $pg->email ?? ('#' . $pg->user_id) }}</td>
            <td>{{ is_array($pg->payload) ? count($pg->payload) : 0 }}</td>
            <td><span class="qstate q-{{ $pg->state }}">{{ strtoupper($pg->state) }}</span></td>
            <td>{{ $pg->attempts }}</td>
            <td style="color:var(--muted)">{{ optional($pg->updated_at)->format('Y-m-d H:i:s') }}</td>
            <td style="color:#f87171">{{ \Illuminate\Support\Str::limit($pg->error, 80) }}</td>
        </tr>
    @empty
        <tr><td colspan="6" style="color:var(--muted)">Nothing queued for purge.</td></tr>
    @endforelse
    </tbody>
</table>

<style>
    .sec { font-size:1.05rem; font-weight:700; margin:1.6rem 0 .4rem; }
    .hint { color:var(--muted); font-size:.82rem; margin:0 0 .6rem; }
    .hint code { background:rgba(255,255,255,.08); padding:.05rem .3rem; border-radius:.25rem; }
    table.dtbl { width:100%; border-collapse:collapse; font-size:.9rem; margin-bottom:.5rem; }
    table.dtbl th, table.dtbl td { text-align:left; padding:.55rem .7rem; border-bottom:1px solid rgba(255,255,255,.08); }
    table.dtbl th { color:var(--muted); font-weight:600; }
    .btn { display:inline-block; background:#26272b; color:#e6e7ea; border:1px solid rgba(255,255,255,.14);
        border-radius:.45rem; padding:.3rem .7rem; font-size:.82rem; text-decoration:none; }
    .btn:hover { filter:brightness(1.15); }
    .crumb { color:var(--muted); margin-bottom:1rem; font-size:.9rem; }
    .crumb a { color:var(--accent); text-decoration:none; }
    .qstate { font-size:.72rem; font-weight:700; padding:.12rem .5rem; border-radius:.3rem; background:rgba(255,255,255,.06); }
    .q-queued { color:#9aa; } .q-running { color:#fbbf24; } .q-done { color:#6ee7b7; } .q-error { color:#f87171; }
    #jq-grid { margin-bottom:.5rem; }
    #jq-grid .tabulator { background:#16171a; border:1px solid rgba(255,255,255,.10); font-size:.84rem; }
    #jq-grid .tabulator .tabulator-header { background:#1c1d21; }
    #jq-grid .tabulator-row .tabulator-cell { padding:4px 9px; }
    .jq-del { background:transparent;border:none;color:#aab;cursor:pointer;padding:.2rem;line-height:0;border-radius:.35rem }
    .jq-del:hover { color:#f87171;background:rgba(248,113,113,.12) }
    .jq-del svg { width:15px;height:15px }
</style>

<script>
(function () {
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const queueBase = '{{ route('admin.feeds') }}/queue/';
    const data = @json($queueData);
    const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[m]));
    const J = async (url, method, body) => {
        const r = await fetch(url, { method, headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : null });
        let d = {}; try { d = await r.json(); } catch (e) {} return { ok: r.ok, data: d };
    };
    const del = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
    const stateBadge = c => `<span class="qstate q-${esc(c.getValue())}">${esc(String(c.getValue()).toUpperCase())}</span>`;

    const save = async (cell) => {
        const { ok, data } = await J(queueBase + cell.getRow().getData().id, 'PATCH',
            { field: cell.getField(), value: cell.getValue() });
        if (!ok) { cell.restoreOldValue(); alert(data.message || 'Could not save.'); }
    };

    const table = new Tabulator('#jq-grid', {
        data, layout: 'fitColumns', height: data.length > 12 ? '60vh' : undefined,
        editTriggerEvent: 'dblclick', placeholder: 'Queue is empty.',
        columns: [
            { title: 'Provider', field: 'provider', widthGrow: 2 },
            { title: 'User', field: 'email', widthGrow: 2, formatter: c => `<span style="color:var(--muted)">${esc(c.getValue())}</span>` },
            { title: 'Type', field: 'type', width: 110, editor: 'list',
              editorParams: { values: ['xtream', 'm3u', 'xmltv', 'manual'] },
              formatter: c => esc(String(c.getValue()).toUpperCase()) },
            { title: 'State', field: 'state', width: 120, editor: 'list',
              editorParams: { values: ['queued', 'running', 'done', 'error'] }, formatter: stateBadge },
            { title: 'Attempts', field: 'attempts', width: 100, hozAlign: 'right', editor: 'number', editorParams: { min: 0 } },
            { title: 'Errors', field: 'error', width: 90, hozAlign: 'right', editor: 'number', editorParams: { min: 0 } },
            { title: 'Updated', field: 'updated', width: 165, formatter: c => `<span style="color:var(--muted)">${esc(c.getValue())}</span>` },
            { title: '', field: '_d', width: 46, hozAlign: 'center', headerSort: false,
              formatter: () => `<button class="jq-del" title="Delete job & disable provider">${del}</button>`,
              cellClick: async (e, c) => {
                  if (!e.target.closest('button')) return;
                  if (!confirm('Delete this job? Its provider will be disabled.')) return;
                  const { ok, data } = await J(queueBase + c.getRow().getData().id, 'DELETE');
                  if (ok) c.getRow().delete(); else alert(data.message || 'Could not delete.');
              } },
        ],
    });

    // type & state cells save on edit
    table.on('cellEdited', save);
})();
</script>
@endsection
