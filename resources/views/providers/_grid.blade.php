{{-- Shared providers grid: Tabulator + bottom icon toolbar + add/edit & log overlays.
     wire:navigate-safe (Flux/Livewire): assets load once, init runs on every navigation. --}}

@assets
<link href="https://cdnjs.cloudflare.com/ajax/libs/tabulator/6.3.1/css/tabulator_midnight.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/tabulator/6.3.1/js/tabulator.min.js"></script>
@endassets

<meta name="csrf-token" content="{{ csrf_token() }}">

<style>
    .gx-card { --gx-accent:#f47521; }
    .gx-card .tabulator { background:#16171a; border:1px solid rgba(255,255,255,.10); border-radius:.6rem .6rem 0 0; font-size:.8rem; }
    .gx-card .tabulator .tabulator-header { background:#1c1d21; font-size:.78rem; }
    .gx-card .tabulator .tabulator-header .tabulator-col .tabulator-col-content { padding:5px 8px; }
    .gx-card .tabulator-row { min-height:0; }
    .gx-card .tabulator-row .tabulator-cell { padding:3px 8px; height:auto; }
    .gx-card .tabulator-row.tabulator-row-even { background:#191a1e; }
    .gx-card .tabulator-placeholder { color:#9aa; }
    .gx-toolbar { display:flex; gap:.4rem; align-items:center; background:#1c1d21;
        border:1px solid rgba(255,255,255,.10); border-top:none; border-radius:0 0 .6rem .6rem; padding:.45rem .6rem; }
    .gx-toolbar button { background:transparent; border:1px solid transparent; color:#cbd; cursor:pointer;
        padding:.3rem; border-radius:.35rem; line-height:0; }
    .gx-toolbar button:hover { background:rgba(255,255,255,.10); color:#fff; }
    .gx-toolbar svg { width:18px; height:18px; }
    .gx-act { display:inline-flex; gap:.5rem; }
    .gx-act button { background:transparent; border:none; color:#aab; cursor:pointer; padding:.2rem; border-radius:.35rem; line-height:0; }
    .gx-act button:hover { color:#fff; background:rgba(255,255,255,.08); }
    .gx-act button.danger:hover { color:#f87171; background:rgba(248,113,113,.12); }
    .gx-act svg { width:16px; height:16px; }
    .gx-ok { color:#6ee7b7; } .gx-fail { color:#f87171; } .gx-never { color:#9aa; } .gx-warn { color:#fbbf24; }
    .gx-state { font-size:.72rem; font-weight:700; padding:.1rem .5rem; border-radius:.3rem; background:rgba(255,255,255,.06); }

    .gx-overlay { position:fixed; inset:0; background:rgba(0,0,0,.6); display:none; align-items:flex-start;
        justify-content:center; z-index:60; padding:4rem 1rem; }
    .gx-overlay.show { display:flex; }
    .gx-modal { background:#1b1c20; border:1px solid rgba(255,255,255,.14); border-radius:.8rem; width:100%;
        max-width:30rem; padding:1.3rem 1.4rem; color:#e6e7ea; }
    .gx-modal.wide { max-width:70rem; }
    .gx-modal .tabulator { background:#16171a; border:1px solid rgba(255,255,255,.10); font-size:.8rem; }
    .gx-modal .tabulator .tabulator-header { background:#1c1d21; font-size:.78rem; }
    .gx-modal .tabulator-row .tabulator-cell { padding:3px 8px; }
    .gx-browse-head { display:flex; align-items:center; gap:.6rem; margin-bottom:.8rem; flex-wrap:wrap; }
    .gx-browse-head input { flex:1; min-width:12rem; background:#0e0f13; border:1px solid rgba(255,255,255,.16);
        color:#e6e7ea; border-radius:.5rem; padding:.4rem .6rem; font-size:.85rem; }
    .gx-count { font-size:.78rem; color:#9aa; }
    .gx-act-del { background:transparent; border:none; color:#aab; cursor:pointer; padding:.2rem; border-radius:.35rem; line-height:0; }
    .gx-act-del:hover { color:#f87171; background:rgba(248,113,113,.12); }
    .gx-act-del svg { width:15px; height:15px; }
    .gx-modal h2 { font-size:1.1rem; font-weight:800; margin-bottom:1rem; }
    .gx-field { margin-bottom:.8rem; }
    .gx-field label { display:block; font-size:.8rem; color:#aab; margin-bottom:.25rem; }
    .gx-field input, .gx-field select { width:100%; background:#0e0f13; border:1px solid rgba(255,255,255,.16);
        color:#e6e7ea; border-radius:.5rem; padding:.45rem .6rem; font-size:.9rem; }
    .gx-row2 { display:flex; gap:.8rem; } .gx-row2 > * { flex:1; }
    .gx-check { display:flex; align-items:center; gap:.5rem; }
    .gx-check input { width:auto; }
    .gx-modal-actions { display:flex; justify-content:flex-end; gap:.6rem; margin-top:1.2rem; }
    .gx-btn { background:var(--gx-accent,#f47521); color:#1a1205; border:none; font-weight:700; padding:.5rem .9rem;
        border-radius:.55rem; cursor:pointer; font-size:.9rem; }
    .gx-btn.secondary { background:#26272b; color:#e6e7ea; border:1px solid rgba(255,255,255,.14); }
    .gx-btn:hover { filter:brightness(1.06); }
    .gx-err { color:#f87171; font-size:.85rem; min-height:1.1rem; margin-top:.3rem; }
    .gx-log { max-height:22rem; overflow:auto; font:.8rem/1.5 ui-monospace,monospace; }
    .gx-log .e { padding:.45rem .2rem; border-bottom:1px solid rgba(255,255,255,.07); }
    .gx-log .t { color:#9aa; }
</style>

<div class="gx-card">
    <div id="provider-grid"></div>
    <div class="gx-toolbar">
        <button title="Add provider" onclick="GXP.openAdd()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
        </button>
        <button title="Reload" onclick="GXP.reload()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        </button>
    </div>
</div>

{{-- Add / Edit overlay --}}
<div class="gx-overlay" id="gx-form-overlay">
    <div class="gx-modal">
        <h2 id="gx-form-title">Add provider</h2>
        <input type="hidden" id="f-id">
        <div class="gx-field"><label>Name</label><input id="f-name" maxlength="128"></div>
        <div class="gx-field"><label>Type</label>
            <select id="f-type" onchange="GXP.syncType()">
                <option value="xtream">Xtream</option>
                <option value="m3u">M3U</option>
                <option value="xmltv">M3U Guide XML</option>
                <option value="manual">Manual</option>
            </select>
        </div>
        <div class="gx-field" data-when="url"><label id="f-url-label">URL</label><input id="f-url" maxlength="1024" placeholder="https://…"></div>
        <div class="gx-field" data-when="xtream"><label>Username</label><input id="f-username" maxlength="255"></div>
        <div class="gx-field" data-when="xtream"><label>Password</label><input id="f-password" type="password" maxlength="255"></div>
        <div class="gx-row2">
            <div class="gx-field"><label>EPG shift (hrs)</label><input id="f-myshift" type="number" min="-23" max="23" value="0"></div>
            <div class="gx-field"><label>Refresh hour</label>
                <select id="f-refresh">
                    <option value="">Auto (1–3 am)</option>
                    @for ($h = 0; $h < 24; $h++)
                        <option value="{{ $h }}">{{ sprintf('%02d', $h) }}:00</option>
                    @endfor
                </select>
            </div>
        </div>
        <div class="gx-field gx-check"><input type="checkbox" id="f-enabled"><label style="margin:0">Enabled</label></div>
        <div class="gx-err" id="gx-form-err"></div>
        <div class="gx-modal-actions">
            <button class="gx-btn secondary" onclick="GXP.closeForm()">Cancel</button>
            <button class="gx-btn" id="gx-save" onclick="GXP.save()">Submit</button>
        </div>
    </div>
</div>

{{-- Channel browser overlay --}}
<div class="gx-overlay" id="gx-browse-overlay">
    <div class="gx-modal wide">
        <div class="gx-browse-head">
            <h2 style="font-size:1.1rem;font-weight:800;margin:0">Channels — <span id="gx-browse-name"></span></h2>
            <input id="gx-browse-search" placeholder="Filter name / group / tvg-name…">
            <span class="gx-count" id="gx-browse-count"></span>
        </div>
        <div id="provider-channels"></div>
        <div class="gx-modal-actions"><button class="gx-btn secondary" onclick="GXP.closeBrowse()">Close</button></div>
    </div>
</div>

{{-- Log overlay --}}
<div class="gx-overlay" id="gx-log-overlay">
    <div class="gx-modal">
        <h2>Update log — <span id="gx-log-name"></span> <span class="gx-state" id="gx-log-state">…</span></h2>
        <div class="gx-log" id="gx-log-body"></div>
        <div class="gx-modal-actions"><button class="gx-btn secondary" onclick="GXP.closeLog()">Close</button></div>
    </div>
</div>

<script>
if (!window.GXP) {
    window.GXP = (function () {
        const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';
        const J = async (url, method = 'GET', body = null) => {
            const r = await fetch(url, {
                method,
                headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json',
                           ...(body ? { 'Content-Type': 'application/json' } : {}) },
                body: body ? JSON.stringify(body) : null,
            });
            let data = {}; try { data = await r.json(); } catch (e) {}
            return { ok: r.ok, status: r.status, data };
        };
        const $ = id => document.getElementById(id);
        const icon = p => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${p}</svg>`;
        const ICONS = {
            refresh: icon('<path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>'),
            log:     icon('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8"/>'),
            browse:  icon('<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 9v12"/>'),
            edit:    icon('<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>'),
            del:     icon('<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'),
        };
        const sClass = s => s === 'ok' ? 'gx-ok' : (s === 'failed' ? 'gx-fail' : 'gx-never');
        let table = null;

        function init() {
            const el = $('provider-grid');
            if (!el || el.__built || !window.Tabulator) return;
            el.__built = true;
            table = new Tabulator(el, {
                layout: 'fitColumns', rowHeight: 30, maxHeight: '170px', editTriggerEvent: 'dblclick',
                placeholder: 'No providers yet — use + to add one.',
                ajaxURL: '{{ route('providers.data') }}',
                columns: [
                    { title: 'Name', field: 'name', widthGrow: 2, editor: 'input', cellEdited: c => GXP.saveCell(c) },
                    { title: 'URL', field: 'url', widthGrow: 3, editor: 'input', cellEdited: c => GXP.saveCell(c),
                      formatter: c => `<span style="color:#8ab4f8">${c.getValue() ?? ''}</span>` },
                    { title: 'Enable', field: 'enabled', width: 90, hozAlign: 'center',
                      formatter: c => `<input type="checkbox" ${c.getValue() ? 'checked' : ''} style="pointer-events:none">`,
                      cellClick: (e, c) => { const d = c.getRow().getData(); GXP.toggle(d.id, d.name); } },
                    { title: 'Type', field: 'type', width: 100, formatter: c => (c.getValue() || '').toUpperCase() },
                    { title: 'Last Refresh', field: 'last_refresh_at', width: 170,
                      formatter: c => { const d = c.getRow().getData();
                        return `<span class="${sClass(d.last_status)}">${d.last_refresh_at ?? '—'}</span>`; } },
                    { title: 'Actions', field: '_act', width: 150, hozAlign: 'center', headerSort: false,
                      formatter: () => `<span class="gx-act">
                            <button data-a="refresh" title="Refresh">${ICONS.refresh}</button>
                            <button data-a="browse" title="Browse channels">${ICONS.browse}</button>
                            <button data-a="log" title="Log">${ICONS.log}</button>
                            <button data-a="edit" title="Edit">${ICONS.edit}</button>
                            <button data-a="del" class="danger" title="Delete">${ICONS.del}</button></span>`,
                      cellClick: (e, c) => {
                            const a = e.target.closest('button')?.dataset.a; if (!a) return;
                            const d = c.getRow().getData();
                            ({ refresh: () => GXP.refresh(d.id, d.name), browse: () => GXP.openBrowse(d.id, d.name),
                               log: () => GXP.openLog(d.id, d.name),
                               edit: () => GXP.openEdit(d.id), del: () => GXP.del(d.id, d.name) }[a])();
                      } },
                ],
            });
        }

        const reload = () => table && table.replaceData();

        function syncType() {
            const t = $('f-type').value;
            document.querySelectorAll('[data-when]').forEach(el => {
                const w = el.dataset.when;
                el.style.display = ((w === 'url' && t !== 'manual') || (w === 'xtream' && t === 'xtream')) ? '' : 'none';
            });
            $('f-url-label').textContent = t === 'xtream' ? 'Server URL' : 'URL';
        }

        function fill(d) {
            $('f-id').value = d.id ?? '';
            $('f-name').value = d.name ?? '';
            $('f-type').value = d.type ?? 'xtream';
            $('f-url').value = d.url ?? '';
            $('f-username').value = d.username ?? '';
            $('f-password').value = d.password ?? '';
            $('f-myshift').value = d.myshift ?? 0;
            $('f-refresh').value = (d.refresh_hour === null || d.refresh_hour === undefined) ? '' : d.refresh_hour;
            $('f-enabled').checked = !!d.enabled;
            $('gx-form-err').textContent = '';
            syncType();
        }

        function openAdd() {
            fill({ type: 'xtream', enabled: true, refresh_hour: '' });
            $('f-password').placeholder = '';
            $('gx-form-title').textContent = 'Add provider';
            $('gx-form-overlay').classList.add('show');
        }
        async function openEdit(id) {
            const { ok, data } = await J('/providers/' + id);
            if (!ok) return alert('Could not load provider.');
            fill(data);
            $('f-password').placeholder = 'leave blank to keep';
            $('gx-form-title').textContent = 'Edit provider';
            $('gx-form-overlay').classList.add('show');
        }
        const closeForm = () => $('gx-form-overlay').classList.remove('show');

        const payload = () => ({
            name: $('f-name').value.trim(),
            type: $('f-type').value,
            url: $('f-url').value.trim() || null,
            username: $('f-username').value.trim() || null,
            password: $('f-password').value,
            myshift: parseInt($('f-myshift').value || '0', 10),
            refresh_hour: $('f-refresh').value === '' ? null : parseInt($('f-refresh').value, 10),
            enabled: $('f-enabled').checked,
        });

        async function save() {
            const id = $('f-id').value;
            const name = $('f-name').value.trim();
            const btn = $('gx-save'); btn.disabled = true; btn.textContent = 'Validating…';
            $('gx-form-err').textContent = '';
            const { ok, data } = id ? await J('/providers/' + id, 'PUT', payload())
                                    : await J('/providers', 'POST', payload());
            btn.disabled = false; btn.textContent = 'Submit';
            if (ok) { closeForm(); reload(); if (data.msgid) openFeed(data.msgid, name); }
            else { $('gx-form-err').textContent = data.message || 'Could not save (check the fields and URL/type).'; }
        }

        async function toggle(id, name) {
            const { data } = await J('/providers/' + id + '/toggle', 'POST');
            reload();
            if (data && data.msgid) openFeed(data.msgid, name || '');
        }

        async function saveCell(cell) {
            const id = cell.getRow().getData().id;
            const { ok, data } = await J('/providers/' + id + '/cell', 'PATCH',
                { field: cell.getField(), value: cell.getValue() });
            if (!ok) { cell.restoreOldValue(); alert(data.message || 'Could not save that change.'); }
        }

        async function refresh(id, name) {
            const { ok, data } = await J('/providers/' + id + '/refresh', 'POST');
            reload();
            if (ok && data.msgid) openFeed(data.msgid, name);
        }

        async function del(id, name) { if (!confirm('Delete provider "' + name + '"?')) return; await J('/providers/' + id, 'DELETE'); reload(); }

        // ----- live feed/log overlay (polls feed_logs by msgid) -----
        let feedTimer = null, feedSince = 0, feedMsgid = null;
        const levelClass = l => l === 'error' ? 'gx-fail' : (l === 'warn' ? 'gx-warn' : 'gx-ok');

        function appendLines(lines) {
            const body = $('gx-log-body');
            lines.forEach(l => {
                const div = document.createElement('div'); div.className = 'e';
                div.innerHTML = `<span class="t">${l.at ?? ''}</span> <span class="${levelClass(l.level)}">[${(l.level || 'info').toUpperCase()}]</span> ${(l.message || '').replace(/</g, '&lt;')}`;
                body.appendChild(div);
                if (l.id > feedSince) feedSince = l.id;
            });
            body.scrollTop = body.scrollHeight;
        }

        function stopFeed() { if (feedTimer) { clearInterval(feedTimer); feedTimer = null; } }

        async function pollFeed() {
            if (!feedMsgid) return;
            const { ok, data } = await J('/providers/feed/' + feedMsgid + '?since=' + feedSince);
            if (!ok) { stopFeed(); return; }
            if (data.logs && data.logs.length) appendLines(data.logs);
            const badge = $('gx-log-state');
            badge.textContent = (data.state || '').toUpperCase();
            badge.className = 'gx-state ' + (data.state === 'error' ? 'gx-fail' : (data.state === 'done' ? 'gx-ok' : 'gx-never'));
            if (data.done) stopFeed();
        }

        function openFeed(msgid, name) {
            stopFeed();
            feedMsgid = msgid; feedSince = 0;
            $('gx-log-name').textContent = name || '';
            $('gx-log-body').innerHTML = '';
            $('gx-log-state').textContent = '…';
            $('gx-log-overlay').classList.add('show');
            pollFeed();
            feedTimer = setInterval(pollFeed, 1500);
        }

        async function openLog(id, name) {
            const { data } = await J('/providers/' + id + '/logs');
            if (!data.msgid) {
                $('gx-log-name').textContent = name || '';
                $('gx-log-state').textContent = '—';
                $('gx-log-body').innerHTML = '<div class="e t">No update has run for this provider yet.</div>';
                $('gx-log-overlay').classList.add('show');
                return;
            }
            openFeed(data.msgid, name);
        }
        const closeLog = () => { stopFeed(); $('gx-log-overlay').classList.remove('show'); };

        // ----- channel browser (remote-paginated grid over the provider's SQLite store) -----
        let browseTable = null, browseProvider = null, searchTimer = null;

        function openBrowse(id, name) {
            browseProvider = id;
            $('gx-browse-name').textContent = name || '';
            $('gx-browse-search').value = '';
            $('gx-browse-count').textContent = '';
            $('gx-browse-overlay').classList.add('show');
            if (browseTable) { browseTable.destroy(); browseTable = null; }
            browseTable = new Tabulator('#provider-channels', {
                layout: 'fitColumns', height: '52vh', editTriggerEvent: 'dblclick',
                placeholder: 'No channels — process this provider first.',
                pagination: true, paginationMode: 'remote', paginationSize: 50,
                ajaxURL: '/providers/' + id + '/channels',
                ajaxParams: () => ({ search: $('gx-browse-search').value || '' }),
                ajaxResponse: (url, params, response) => {
                    $('gx-browse-count').textContent = (response.total ?? 0) + ' channels';
                    return response;
                },
                columns: [
                    { title: 'Name', field: 'name', widthGrow: 2, editor: 'input', cellEdited: c => GXP.saveChannel(c) },
                    { title: 'tvg-name', field: 'tvg_name', widthGrow: 2, editor: 'input', cellEdited: c => GXP.saveChannel(c) },
                    { title: 'Group', field: 'group_title', widthGrow: 1, editor: 'input', cellEdited: c => GXP.saveChannel(c) },
                    { title: 'Type', field: 'type', width: 80, editor: 'list', editorParams: { values: ['Live', 'VOD', 'user'] }, cellEdited: c => GXP.saveChannel(c) },
                    { title: 'URL', field: 'url', widthGrow: 3, editor: 'input', cellEdited: c => GXP.saveChannel(c),
                      formatter: c => `<span style="color:#8ab4f8">${c.getValue() ?? ''}</span>` },
                    { title: '', field: '_d', width: 50, hozAlign: 'center', headerSort: false,
                      formatter: () => `<button class="gx-act-del" title="Delete">${ICONS.del}</button>`,
                      cellClick: (e, c) => { if (e.target.closest('button')) GXP.delChannel(c.getRow().getData().id); } },
                ],
            });
        }

        async function saveChannel(cell) {
            const { ok, data } = await J('/providers/' + browseProvider + '/channels/' + cell.getRow().getData().id, 'PATCH',
                { field: cell.getField(), value: cell.getValue() });
            if (!ok) { cell.restoreOldValue(); alert(data.message || 'Could not save that change.'); }
        }

        async function delChannel(cid) {
            if (!confirm('Delete this channel from the store?')) return;
            await J('/providers/' + browseProvider + '/channels/' + cid, 'DELETE');
            if (browseTable) browseTable.replaceData();
        }

        function closeBrowse() {
            $('gx-browse-overlay').classList.remove('show');
            if (browseTable) { browseTable.destroy(); browseTable = null; }
        }

        document.addEventListener('input', e => {
            if (e.target && e.target.id === 'gx-browse-search' && browseTable) {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => browseTable.setPage(1), 300);
            }
        });

        document.addEventListener('livewire:navigated', init);
        document.addEventListener('DOMContentLoaded', init);

        return { init, reload, syncType, openAdd, openEdit, closeForm, save, toggle, saveCell, refresh, del, openLog, closeLog, openBrowse, closeBrowse, saveChannel, delChannel };
    })();
}
// run now in case the listeners' events already fired before this script parsed
if (window.GXP) window.GXP.init();
</script>
