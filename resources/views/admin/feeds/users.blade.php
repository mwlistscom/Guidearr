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
<div id="users-grid"></div>

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

{{-- Job Queue edit modal. Greyed fields are identity/derived and cannot be changed here. --}}
<div id="jq-modal" class="jqm-backdrop" hidden>
    <div class="jqm" role="dialog" aria-modal="true" aria-labelledby="jqm-title">
        <div class="jqm-head"><h3 id="jqm-title">Edit job</h3>
            <button type="button" class="jqm-x" data-close aria-label="Close">&times;</button></div>
        <div class="jqm-body">
            <label>Provider <input id="jqm-provider" type="text" disabled></label>
            <label>User&nbsp;# <input id="jqm-user" type="text" disabled></label>
            <label>Type
                <select id="jqm-type">
                    <option value="xtream">xtream</option><option value="m3u">m3u</option>
                    <option value="xmltv">xmltv</option><option value="manual">manual</option>
                </select>
            </label>
            <label>State
                <select id="jqm-state">
                    <option value="queued">queued</option><option value="running">running</option>
                    <option value="done">done</option><option value="error">error</option>
                </select>
            </label>
            <label>Attempts <input id="jqm-attempts" type="number" min="0"></label>
            <label>Errors <input id="jqm-error" type="number" min="0"></label>
            <label>Next start <input id="jqm-next" type="text" disabled></label>
            <label>Updated <input id="jqm-updated" type="text" disabled></label>
        </div>
        <div class="jqm-foot">
            <button type="button" class="ghost" data-close>Cancel</button>
            <button type="button" id="jqm-save">Save changes</button>
        </div>
    </div>
</div>

{{-- Delete-user confirmation dialog (replaces stacked confirm() prompts). --}}
<div id="del-modal" class="jqm-backdrop" hidden>
    <div class="jqm" role="dialog" aria-modal="true" aria-labelledby="del-title" style="width:min(28rem,92vw)">
        <div class="jqm-head"><h3 id="del-title">Delete user</h3>
            <button type="button" class="jqm-x" data-delclose aria-label="Close">&times;</button></div>
        <div class="del-body">
            <p id="del-admin-warn" class="del-warn" hidden>⚠ This is an <strong>admin</strong> account — deleting it removes an administrator.</p>
            <p>Delete <strong id="del-email"></strong> and <strong>all their playlists and providers</strong>? This cannot be undone.</p>
            <label class="del-check"><input type="checkbox" id="del-ban"> Also add this email to the ban list (blocks re-registration)</label>
        </div>
        <div class="jqm-foot">
            <button type="button" class="ghost" data-delclose>Cancel</button>
            <button type="button" id="del-confirm" class="danger">Delete user</button>
        </div>
    </div>
</div>

{{-- Provider log viewer (opened from the Job Queue Actions column). --}}
<div id="log-modal" class="jqm-backdrop" hidden>
    <div class="jqm" role="dialog" aria-modal="true" aria-labelledby="log-title" style="width:min(56rem,95vw)">
        <div class="jqm-head"><h3 id="log-title">Provider log</h3>
            <button type="button" class="jqm-x" data-logclose aria-label="Close">&times;</button></div>
        <pre id="log-out" class="log-out">Loading…</pre>
    </div>
</div>

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
    .q-cold { color:#7dd3fc; background:rgba(125,211,252,.10); } .q-disabled { color:#9aa; }
    #jq-grid .tabulator-row.jq-dim { opacity:.5; }
    #jq-grid { margin-bottom:.5rem; }
    #jq-grid .tabulator { background:#16171a; border:1px solid rgba(255,255,255,.10); font-size:.84rem; }
    #jq-grid .tabulator .tabulator-header { background:#1c1d21; }
    #jq-grid .tabulator-row .tabulator-cell { padding:4px 9px; }
    .jq-del { background:transparent;border:none;color:#aab;cursor:pointer;padding:.2rem;line-height:0;border-radius:.35rem }
    .jq-del:hover { color:#f87171;background:rgba(248,113,113,.12) }
    .jq-del svg { width:15px;height:15px }
    .jq-edit { background:transparent;border:none;color:#aab;cursor:pointer;padding:.2rem;line-height:0;border-radius:.35rem }
    .jq-edit:hover { color:var(--accent);background:rgba(244,117,33,.12) }
    .jq-edit svg, .u-del svg { width:15px;height:15px }
    .jq-run { background:transparent;border:none;color:#aab;cursor:pointer;padding:.2rem;line-height:0;border-radius:.35rem;margin-right:.15rem }
    .jq-run:hover { color:#6ee7b7;background:rgba(110,231,183,.12) }
    .jq-run svg { width:15px;height:15px }
    .jq-log { background:transparent;border:none;color:#aab;cursor:pointer;padding:.2rem;line-height:0;border-radius:.35rem;margin-right:.15rem }
    .jq-log:hover { color:#8ab4f8;background:rgba(138,180,248,.12) }
    .jq-log svg { width:15px;height:15px }
    .log-out { margin:0;padding:1rem 1.1rem;max-height:72vh;overflow:auto;background:#0c0d10;
        font-family:ui-monospace,Menlo,monospace;font-size:.78rem;line-height:1.5;color:#cdd2da;
        white-space:pre-wrap;word-break:break-word }
    .log-out .ll.warn { color:#fbbf24 }
    .log-out .ll.error { color:#f87171 }
    .log-out .ll .lt { color:var(--muted);margin-right:.4rem }
    .u-del { background:transparent;border:none;color:#aab;cursor:pointer;padding:.2rem;line-height:0;border-radius:.35rem }
    .u-del:hover { color:#f87171;background:rgba(248,113,113,.12) }
    /* Job-edit modal */
    .jqm-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.6); display:flex; align-items:center;
        justify-content:center; z-index:50; }
    .jqm-backdrop[hidden] { display:none; }
    .jqm { background:#16171a; border:1px solid rgba(255,255,255,.12); border-radius:.7rem; width:min(30rem,92vw);
        max-height:88vh; overflow:auto; box-shadow:0 20px 60px rgba(0,0,0,.5); }
    .jqm-head { display:flex; align-items:center; justify-content:space-between; padding:1rem 1.2rem;
        border-bottom:1px solid rgba(255,255,255,.08); }
    .jqm-head h3 { font-size:1.05rem; font-weight:700; }
    .jqm-x { background:transparent; border:none; color:var(--muted); font-size:1.4rem; cursor:pointer; line-height:1; padding:0; }
    .jqm-x:hover { color:#fff; }
    .jqm-body { padding:1rem 1.2rem; display:grid; grid-template-columns:1fr 1fr; gap:.7rem 1rem; }
    .jqm-body label { display:flex; flex-direction:column; gap:.25rem; font-size:.78rem; color:var(--muted); margin:0; }
    .jqm-body input, .jqm-body select { background:#0e0f13; border:1px solid rgba(255,255,255,.14); color:#e6e7ea;
        border-radius:.4rem; padding:.4rem .5rem; font-size:.85rem; width:100%; }
    .jqm-body input:disabled { color:var(--muted); background:#111216; cursor:not-allowed; }
    .jqm-foot { display:flex; justify-content:flex-end; gap:.6rem; padding:.9rem 1.2rem;
        border-top:1px solid rgba(255,255,255,.08); }
    .jqm-foot button { border:none; border-radius:.45rem; padding:.45rem .9rem; font-size:.85rem; cursor:pointer;
        background:var(--accent); color:#160a02; font-weight:700; }
    .jqm-foot button.ghost { background:transparent; color:#e6e7ea; border:1px solid rgba(255,255,255,.18); font-weight:600; }
    .jqm-foot button.danger { background:#dc2626; color:#fff; }
    .jqm-foot button:hover { filter:brightness(1.1); }
    .del-body { padding:1rem 1.2rem; }
    .del-body p { color:#cdd2da; font-size:.9rem; margin:0 0 .9rem; line-height:1.5; }
    .del-check { display:flex; align-items:center; gap:.5rem; font-size:.85rem; color:#cdd2da; cursor:pointer; }
    .del-check input { width:auto; }
    .del-warn { color:#fca5a5; background:rgba(248,113,113,.10); border:1px solid rgba(248,113,113,.35);
        padding:.5rem .7rem; border-radius:.4rem; font-size:.84rem; margin:0 0 .8rem; }
    .u-self { color:var(--muted); opacity:.5; padding:.2rem .4rem; font-size:.9rem; }
    /* Job-queue action icons sit inline in one Actions column. */
    .jq-edit + .jq-del { margin-left:.25rem; }
    .u-del { margin-left:.35rem; vertical-align:middle; }
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
    const stateBadge = c => {
        const r = c.getRow().getData();
        if (r.disabled) {
            return r.cold
                ? `<span class="qstate q-cold" title="Disabled by the cold-provider reaper (idle 14 days). Revives automatically on next access.">COLD</span>`
                : `<span class="qstate q-disabled" title="Provider is disabled — not scheduled for refresh.">DISABLED</span>`;
        }
        return `<span class="qstate q-${esc(c.getValue())}">${esc(String(c.getValue()).toUpperCase())}</span>`;
    };

    const save = async (cell) => {
        const { ok, data } = await J(queueBase + cell.getRow().getData().id, 'PATCH',
            { field: cell.getField(), value: cell.getValue() });
        if (!ok) { cell.restoreOldValue(); alert(data.message || 'Could not save.'); }
    };

    const edit = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
    const runIco = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>';
    const logIco = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>';
    const muted = v => `<span style="color:var(--muted)">${esc(v)}</span>`;

    const table = new Tabulator('#jq-grid', {
        data, layout: 'fitColumns',
        pagination: true, paginationSize: 25, paginationCounter: 'rows',
        editTriggerEvent: 'dblclick', placeholder: 'Queue is empty.',
        rowFormatter: row => { row.getElement().classList.toggle('jq-dim', !!row.getData().disabled); },
        columns: [
            { title: 'User #', field: 'user_id', width: 78, hozAlign: 'right', formatter: c => muted(c.getValue() ?? '—') },
            { title: 'Provider', field: 'provider', widthGrow: 2 },
            { title: 'User', field: 'email', widthGrow: 2, formatter: c => muted(c.getValue()) },
            { title: 'Type', field: 'type', width: 100, editor: 'list',
              editorParams: { values: ['xtream', 'm3u', 'xmltv', 'manual'] },
              formatter: c => esc(String(c.getValue()).toUpperCase()) },
            { title: 'State', field: 'state', width: 110, editor: 'list',
              editorParams: { values: ['queued', 'running', 'done', 'error'] }, formatter: stateBadge },
            { title: 'Attempts', field: 'attempts', width: 92, hozAlign: 'right', editor: 'number', editorParams: { min: 0 } },
            { title: 'Errors', field: 'error', width: 82, hozAlign: 'right', editor: 'number', editorParams: { min: 0 } },
            { title: 'Next start', field: 'next', width: 130, formatter: c => {
                  const v = c.getValue();
                  return v === 'due now' ? `<span style="color:#fbbf24">due now</span>` : muted(v);
              } },
            { title: 'Updated', field: 'updated', width: 155, formatter: c => muted(c.getValue()) },
            { title: 'Actions', field: '_act', width: 142, hozAlign: 'center', headerSort: false,
              formatter: () => `<button class="jq-run" title="Refresh this provider now">${runIco}</button><button class="jq-log" title="View this provider's log">${logIco}</button><button class="jq-edit" title="Edit job">${edit}</button><button class="jq-del" title="Delete job & disable provider">${del}</button>`,
              cellClick: async (e, c) => {
                  const row = c.getRow();
                  if (e.target.closest('.jq-run')) {
                      const d = row.getData();
                      const note = d.disabled ? '\nThis provider is disabled — running it will re-enable it.' : '';
                      if (!confirm('Refresh this provider now?' + note)) return;
                      const { ok, data } = await J(queueBase + d.id + '/run', 'POST');
                      if (ok) row.update({ state: 'queued', disabled: false, cold: false, next: 'due now' });
                      else alert(data.message || 'Could not queue the refresh.');
                      return;
                  }
                  if (e.target.closest('.jq-log')) { openLog(row.getData()); return; }
                  if (e.target.closest('.jq-edit')) { openEdit(row); return; }
                  if (e.target.closest('.jq-del')) {
                      if (!confirm('Delete this job? Its provider will be disabled.')) return;
                      const { ok, data } = await J(queueBase + row.getData().id, 'DELETE');
                      if (ok) row.delete(); else alert(data.message || 'Could not delete.');
                  }
              } },
        ],
    });

    // type & state cells save on edit (inline double-click editing stays available)
    table.on('cellEdited', save);

    // ---- Job edit modal ----
    const modal = document.getElementById('jq-modal');
    const F = id => document.getElementById('jqm-' + id);
    let editRow = null;

    function openEdit(row) {
        editRow = row;
        const d = row.getData();
        F('provider').value = d.provider;
        F('user').value = d.user_id ?? '—';
        F('type').value = d.type;
        F('state').value = d.state;
        F('attempts').value = d.attempts;
        F('error').value = d.error;
        F('next').value = d.next;
        F('updated').value = d.updated;
        modal.hidden = false;
    }
    function closeEdit() { modal.hidden = true; editRow = null; }
    modal.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', closeEdit));
    modal.addEventListener('click', e => { if (e.target === modal) closeEdit(); });

    document.getElementById('jqm-save').addEventListener('click', async () => {
        if (!editRow) return;
        const id = editRow.getData().id;
        const changes = {
            type: F('type').value,
            state: F('state').value,
            attempts: Number(F('attempts').value),
            error: Number(F('error').value),
        };
        const cur = editRow.getData();
        for (const [field, value] of Object.entries(changes)) {
            if (String(cur[field]) === String(value)) continue; // only save what changed
            const { ok, data } = await J(queueBase + id, 'PATCH', { field, value });
            if (!ok) { alert(data.message || `Could not save ${field}.`); return; }
            editRow.update({ [field]: value });
        }
        closeEdit();
    });

    // Users — sortable, paginated 25/page: ID, name, email, providers, playlists, last login, view + delete.
    const usersTable = new Tabulator('#users-grid', {
        data: @json($usersData), layout: 'fitColumns',
        pagination: true, paginationSize: 25, paginationCounter: 'rows',
        initialSort: [{ column: 'name', dir: 'asc' }], placeholder: 'No users.',
        columns: [
            { title: 'ID', field: 'id', width: 70, hozAlign: 'right' },
            { title: 'User', field: 'name', widthGrow: 2 },
            { title: 'Email', field: 'email', widthGrow: 3, formatter: c => muted(c.getValue()) },
            { title: 'Providers', field: 'providers', width: 95, hozAlign: 'right' },
            { title: 'Playlists', field: 'playlists', width: 92, hozAlign: 'right' },
            { title: 'Last login', field: 'lastlogin', width: 120, formatter: c => muted(c.getValue() || 'never') },
            { title: 'Last touch', field: 'lasttouch', width: 120,
              formatter: c => muted(c.getValue() || 'never'),
              headerTooltip: 'Most recent m3u/xtream download or dashboard activity' },
            { title: 'Actions', field: '_act', width: 108, hozAlign: 'center', headerSort: false,
              formatter: c => {
                  const r = c.getRow().getData();
                  const view = `<a class="btn" href="${esc(r.url)}">View</a>`;
                  // No delete control for your own account — you cannot delete yourself.
                  if (r.is_self) return view + `<span class="u-self" title="You cannot delete your own account">—</span>`;
                  return view + `<button class="u-del" title="Delete user + all their playlists">${del}</button>`;
              },
              cellClick: (e, c) => {
                  if (!e.target.closest('.u-del')) return; // let the View link navigate normally
                  openDeleteModal(c.getRow().getData(), () => c.getRow().delete());
              } },
        ],
    });

    // ---- Delete-user dialog (shared by the users grid) ----
    const delModal = document.getElementById('del-modal');
    let delCtx = null;
    function openDeleteModal(row, onDone) {
        if (row.is_self) { alert('You cannot delete your own account.'); return; }
        delCtx = { url: row.delUrl, onDone, isAdmin: !!row.is_admin };
        document.getElementById('del-email').textContent = row.email;
        document.getElementById('del-ban').checked = false;
        document.getElementById('del-admin-warn').hidden = !row.is_admin;
        delModal.hidden = false;
    }
    function closeDel() { delModal.hidden = true; delCtx = null; }
    delModal.querySelectorAll('[data-delclose]').forEach(b => b.addEventListener('click', closeDel));
    delModal.addEventListener('click', e => { if (e.target === delModal) closeDel(); });
    document.getElementById('del-confirm').addEventListener('click', async () => {
        if (!delCtx) return;
        // Admin accounts get an extra, explicit confirmation.
        if (delCtx.isAdmin && !confirm('This is an ADMIN account. Are you absolutely sure you want to delete it and all of its data?')) return;
        const ban = document.getElementById('del-ban').checked ? 1 : 0;
        const { ok, data } = await J(delCtx.url, 'DELETE', { ban });
        if (ok) { if (delCtx.onDone) delCtx.onDone(); closeDel(); }
        else alert((data && data.message) || 'Could not delete user.');
    });

    // ---- Provider log viewer (Job Queue Actions → log icon) ----
    const logModal = document.getElementById('log-modal');
    const logOut = document.getElementById('log-out');
    const logTitle = document.getElementById('log-title');
    function closeLog() { logModal.hidden = true; }
    logModal.querySelectorAll('[data-logclose]').forEach(b => b.addEventListener('click', closeLog));
    logModal.addEventListener('click', e => { if (e.target === logModal) closeLog(); });

    async function openLog(row) {
        logTitle.textContent = 'Log — ' + row.provider;
        logOut.textContent = 'Loading…';
        logModal.hidden = false;
        try {
            const r = await fetch(queueBase + row.id + '/log', { headers: { 'Accept': 'application/json' } });
            const d = await r.json().catch(() => ({}));
            if (!r.ok) { logOut.textContent = (d && d.message) || 'Could not load the log.'; return; }
            if (!d.logs || !d.logs.length) {
                logOut.textContent = 'No log lines for this provider (they may have been trimmed after 14 days).';
                return;
            }
            logOut.innerHTML = d.logs.map(l =>
                `<div class="ll ${esc(l.level)}"><span class="lt">${esc(l.at)}</span>${esc(l.message)}</div>`).join('');
            logOut.scrollTop = logOut.scrollHeight;
        } catch (e) { logOut.textContent = 'Error loading the log.'; }
    }
})();
</script>
@endsection
