{{-- Playlist editor — opens below when a playlist row is selected (GXPLE.open).
     Channels (left, drag + move-to-row#) and Groups (right, drag) with enable/delete/restore. --}}

<style>
    .ple-pane { margin-top:1.2rem; }
    .ple-head { display:flex; align-items:center; gap:.6rem; margin-bottom:.7rem; flex-wrap:wrap; }
    .ple-head h2 { font-size:1.05rem; font-weight:800; margin:0; color:#e6e7ea; }
    .ple-chip { font-size:.78rem; font-weight:700; color:#f47521; background:rgba(244,117,33,.14);
        border:1px solid rgba(244,117,33,.4); border-radius:1rem; padding:.15rem .6rem; }
    .ple-count { font-size:.82rem; color:#9aa0aa; margin-left:auto; }
    .ple-split { display:grid; grid-template-columns:3fr 1fr; gap:1rem; }
    /* grid items default to min-width:auto, which lets a resized column push the track
       (and the table) ever wider in a fitColumns feedback loop — pin to 0 so the table
       stays inside its track and manages its own column widths. */
    .ple-split > div { min-width:0; }
    .ple-pane-filter { width:100%; box-sizing:border-box; background:#0e0f13; border:1px solid rgba(255,255,255,.10);
        border-bottom:none; border-radius:.6rem .6rem 0 0; color:#e6e7ea; padding:.42rem .6rem; font-size:.85rem; }
    .ple-split .tabulator { border-radius:0; }
    .ple-toolbar { display:flex; gap:.4rem; align-items:center; background:#1c1d21; border:1px solid rgba(255,255,255,.10);
        border-top:none; border-radius:0 0 .6rem .6rem; padding:.4rem .5rem; }
    .ple-toolbar button { background:transparent; border:1px solid rgba(255,255,255,.14); color:#cdd2da;
        border-radius:.4rem; padding:.3rem .4rem; cursor:pointer; line-height:0; }
    .ple-toolbar button:hover { color:#fff; border-color:#f47521; }
    .ple-toolbar button.on { color:#fff; border-color:#f47521; background:rgba(244,117,33,.16); }
    .ple-toolbar svg { width:16px; height:16px; }
    .ple-logo { height:22px; max-width:44px; object-fit:contain; vertical-align:middle; }
    .ple-act { display:inline-flex; gap:.35rem; }
    .ple-act button, .pl-x { background:transparent; border:none; color:#aab; cursor:pointer; padding:.2rem; border-radius:.35rem; line-height:0; }
    .ple-act button:hover, .pl-x:hover { color:#fff; background:rgba(255,255,255,.08); }
    .ple-act svg, .pl-x svg { width:15px; height:15px; }
    .ple-split .tabulator-row.tabulator-selected { background:rgba(244,117,33,.16) !important; }
    /* dragged row: high-contrast floating chip so it reads clearly against the dark grid */
    .ple-split .tabulator-row.tabulator-moving {
        background:#f6f7f9 !important; color:#15161a !important;
        border:1px solid #f47521 !important; border-radius:5px;
        box-shadow:0 10px 26px rgba(0,0,0,.6); z-index:60;
    }
    .ple-split .tabulator-row.tabulator-moving .tabulator-cell { color:#15161a !important; border-color:rgba(0,0,0,.12) !important; }
    .ple-split .tabulator-row.tabulator-moving input[type=checkbox] { filter:none; }
    /* placeholder gap where the row will drop */
    .ple-split .tabulator-row.tabulator-moving + .tabulator-row,
    .ple-split .tabulator-row.tabulator-rowMovingReceiver { box-shadow:inset 0 2px 0 #f47521; }
    .ple-split .tabulator-row { cursor:grab; }
    .ple-split .tabulator-row.tabulator-moving { cursor:grabbing; }
    /* editable cells get a subtle affordance on hover */
    .ple-split .tabulator-cell.tabulator-editable:hover { background:rgba(244,117,33,.10); outline:1px solid rgba(244,117,33,.35); outline-offset:-1px; }
    .ple-split .tabulator-cell.tabulator-editing { box-shadow:inset 0 0 0 2px #f47521; padding:0 !important; }
    .ple-split .tabulator-cell.tabulator-editing input,
    .ple-split .tabulator-cell.tabulator-editing select { background:#0e0f13; color:#fff; border:none; height:100%; padding:.3rem .5rem; box-sizing:border-box; }
    .ple-split .tabulator-row .tabulator-row-handle { color:#6b7280; }
    .ple-overlay { position:fixed; inset:0; background:rgba(0,0,0,.6); display:none; align-items:flex-start;
        justify-content:center; padding-top:8vh; z-index:70; }
    .ple-overlay.show { display:flex; }
    .ple-modal { background:#1b1c20; border:1px solid rgba(255,255,255,.14); border-radius:.8rem; width:100%;
        max-width:26rem; padding:1.2rem; color:#e6e7ea; }
    .ple-modal h3 { font-size:1rem; font-weight:800; margin:0 0 .9rem; }
    .ple-field { margin-bottom:.7rem; }
    .ple-field label { display:block; font-size:.78rem; color:#aab; margin-bottom:.2rem; }
    .ple-field input, .ple-field select { width:100%; box-sizing:border-box; background:#0e0f13;
        border:1px solid rgba(255,255,255,.16); color:#e6e7ea; border-radius:.5rem; padding:.42rem .6rem; font-size:.88rem; }
    .ple-field input:disabled { opacity:.5; }
    .ple-err { color:#f87171; font-size:.8rem; min-height:1em; }
    .ple-modal-actions { display:flex; justify-content:flex-end; gap:.5rem; margin-top:1rem; }
    .ple-btn { background:#f47521; color:#fff; border:none; border-radius:.5rem; padding:.45rem .9rem; font-weight:700; cursor:pointer; font-size:.85rem; }
    .ple-btn.secondary { background:#2a2c31; color:#cdd2da; }
</style>

<div class="ple-pane" id="pl-editor-pane" hidden>
    <div class="ple-head">
        <h2>Playlist — <span id="ple-name"></span></h2>
        <span class="ple-chip" id="ple-filter" style="display:none"></span>
        <span class="ple-count" id="ple-count"></span>
    </div>
    <div class="ple-split">
        <div>
            <input class="ple-pane-filter" id="ple-search" placeholder="Filter…">
            <div id="pl-channels"></div>
            <div class="ple-toolbar">
                <button title="Add manual channel" onclick="GXPLE.openAdd()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg></button>
                <button title="Refresh" onclick="GXPLE.reloadChannels()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg></button>
                <button title="Show deleted (undelete)" id="ple-trash-toggle" onclick="GXPLE.toggleDeleted()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
            </div>
        </div>
        <div>
            <input class="ple-pane-filter" id="ple-gsearch" placeholder="Filter…">
            <div id="pl-groups"></div>
            <div class="ple-toolbar">
                <button title="Refresh groups" onclick="GXPLE.loadGroups()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg></button>
            </div>
        </div>
    </div>
</div>

<div class="ple-overlay" id="ple-move-overlay">
    <div class="ple-modal">
        <h3>Move channel</h3>
        <div class="ple-field"><label id="ple-move-label">Move to row #</label><input type="number" id="ple-move-row" min="1" value="1"></div>
        <div class="ple-modal-actions">
            <button class="ple-btn secondary" onclick="GXPLE.closeMove()">Cancel</button>
            <button class="ple-btn" onclick="GXPLE.applyMove()">Move</button>
        </div>
    </div>
</div>

<div class="ple-overlay" id="ple-edit-overlay">
    <div class="ple-modal">
        <h3 id="ple-edit-title">Channel</h3>
        <div class="ple-field"><label>Name</label><input type="text" id="ple-e-name"></div>
        <div class="ple-field"><label>Stream URL</label><input type="text" id="ple-e-url"></div>
        <div class="ple-field"><label>Icon URL</label><input type="text" id="ple-e-logo"></div>
        <div class="ple-field"><label>Group</label><select id="ple-e-group"></select></div>
        <div class="ple-err" id="ple-e-err"></div>
        <div class="ple-modal-actions">
            <button class="ple-btn secondary" onclick="GXPLE.closeEdit()">Cancel</button>
            <button class="ple-btn" id="ple-e-save" onclick="GXPLE.saveEdit()">Save</button>
        </div>
    </div>
</div>

<script>
window.GXPLE = (function () {
    const $ = id => document.getElementById(id);
    const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';
    const J = async (url, method = 'GET', body = null) => {
        const r = await fetch(url, { method, headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : null });
        let data = {}; try { data = await r.json(); } catch (e) {}
        return { ok: r.ok, status: r.status, data };
    };
    const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[m]));
    const ICON = {
        move: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 18l4 4 4-4M8 6l4-4 4 4M12 2v20"/></svg>',
        edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
        del:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        restore: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v6h6"/><path d="M3.51 13a9 9 0 1 0 2.13-9.36L3 7"/></svg>',
    };
    const SIZE = 50;
    let plId = null, chTable = null, grTable = null, groupFilter = null, showDeleted = false, gsearchTimer = null, searchTimer = null;
    let moveCid = null, editCid = null, editManual = false, plGroups = [];

    function setChip() {
        const c = $('ple-filter');
        if (groupFilter) { c.textContent = '● ' + groupFilter; c.style.display = ''; }
        else { c.style.display = 'none'; }
    }

    function close() {
        $('pl-editor-pane').hidden = true;
        if (chTable) { try { chTable.destroy(); } catch (e) {} chTable = null; }
        if (grTable) { try { grTable.destroy(); } catch (e) {} grTable = null; }
    }

    async function open(id, name) {
        console.log('GXPLE.open', id, name);
        plId = id; groupFilter = null; showDeleted = false;
        const gb = document.getElementById('gx-browse-pane'); if (gb) gb.hidden = true; // hide provider browser
        $('ple-name').textContent = name || '';
        $('ple-search').value = ''; $('ple-gsearch').value = '';
        $('ple-trash-toggle').classList.remove('on');
        setChip();
        $('pl-editor-pane').hidden = false;
        $('pl-editor-pane').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        try { await loadGroups(); } catch (e) { console.error('GXPLE loadGroups failed', e); }
        buildChannels();
    }

    async function loadGroups() {
        if (!plId) return;
        const { data } = await J('/playlists/' + plId + '/groups');
        const rows = (data && data.groups) || [];
        plGroups = rows.map(r => r.group_title);
        if (grTable) { grTable.replaceData(rows); return; }
        grTable = new Tabulator('#pl-groups', {
            layout: 'fitColumns', height: '56vh', data: rows, movableRows: true, placeholder: 'No groups.',
            editTriggerEvent: 'dblclick',   // single press = drag/move, double-click = edit
            columns: [
                { title: 'Group', field: 'group_title', widthGrow: 3, editor: 'input',
                  cellEdited: cell => J('/playlists/' + plId + '/groups/' + cell.getRow().getData().id, 'PATCH', { group_title: cell.getValue() }).then(() => { loadGroups(); reloadChannels(); }) },
                { title: 'Ch', field: 'channels', width: 46, hozAlign: 'right' },
                { title: 'On', field: 'enabled', width: 44, hozAlign: 'center', headerSort: false,
                  formatter: c => `<input type="checkbox" ${c.getValue() ? 'checked' : ''} style="pointer-events:none">` },
                { title: '', field: '_d', width: 38, hozAlign: 'center', headerSort: false,
                  formatter: () => `<button class="pl-x" title="Delete group">${ICON.del}</button>` },
            ],
        });
        grTable.on('cellClick', (e, cell) => {
            const f = cell.getField(); const d = cell.getRow().getData();
            if (f === 'enabled') { const on = !d.enabled; J('/playlists/' + plId + '/groups/' + d.id, 'PATCH', { enabled: on }); cell.getRow().update({ enabled: on }); reloadChannels(); }
            else if (f === '_d' && e.target.closest('button')) { if (confirm('Hide group "' + d.group_title + '" (and its channels) from the playlist?')) { J('/playlists/' + plId + '/groups/' + d.id, 'DELETE').then(() => { loadGroups(); reloadChannels(); }); } }
        });
        grTable.on('rowClick', (e, row) => {
            if (e.target.closest('button') || e.target.closest('input') || e.target.closest('.tabulator-editing')) return;
            const t = row.getData().group_title;
            groupFilter = (groupFilter === t) ? null : t; setChip(); reloadChannels();
        });
        grTable.on('rowMoved', (row) => {
            J('/playlists/' + plId + '/groups/' + row.getData().id + '/move', 'POST', { row: row.getPosition(true) }).then(reloadChannels);
        });
    }

    function buildChannels() {
        if (chTable) { try { chTable.destroy(); } catch (e) {} chTable = null; }
        const onEdit = (cell) => {
            const f = cell.getField(); const body = {}; body[f] = cell.getValue();
            J('/playlists/' + plId + '/channels/' + cell.getRow().getData().id, 'PATCH', body)
                .then(() => { if (f === 'group_title') { loadGroups(); reloadChannels(); } });
        };
        chTable = new Tabulator('#pl-channels', {
            layout: 'fitColumns', height: '56vh', movableRows: true,
            editTriggerEvent: 'dblclick',   // single press = drag/move, double-click = edit
            pagination: true, paginationMode: 'remote', paginationSize: SIZE,
            placeholder: 'No channels.',
            ajaxURL: '/playlists/' + plId + '/channels',
            ajaxParams: () => ({ search: $('ple-search').value || '', group: groupFilter || '', deleted: showDeleted ? 'all' : '' }),
            ajaxResponse: (u, p, r) => { $('ple-count').textContent = (r.total ?? 0) + ' channels' + (showDeleted ? ' (incl. deleted)' : ''); return r; },
            rowFormatter: (row) => { const el = row.getElement(); if (row.getData().deleted) { el.style.opacity = '.42'; el.style.textDecoration = 'line-through'; } else { el.style.opacity = ''; el.style.textDecoration = ''; } },
            columns: [
                { title: '#', field: 'row', width: 48, hozAlign: 'right', headerSort: false },
                { title: 'Logo', field: 'tvg_logo', width: 50, hozAlign: 'center', headerSort: false,
                  formatter: c => c.getValue() ? `<img class="ple-logo" src="${esc(c.getValue())}" onerror="this.style.display='none'">` : '' },
                { title: 'On', field: 'enabled', width: 40, hozAlign: 'center', headerSort: false,
                  formatter: c => `<input type="checkbox" ${c.getValue() ? 'checked' : ''} style="pointer-events:none">` },
                { title: 'TVG_ID', field: 'tvg_id', widthGrow: 1.3, editor: 'input', cellEdited: onEdit },
                { title: 'TVG Name', field: 'tvg_name', widthGrow: 1.6, editor: 'input', cellEdited: onEdit },
                { title: 'Title', field: 'name', widthGrow: 1.6, editor: 'input', cellEdited: onEdit,
                  formatter: c => { const d = c.getRow().getData(); return (d.missing ? '<span style="color:#f87171" title="source channel missing">⚠ </span>' : '') + esc(c.getValue()); } },
                { title: 'M3U URL', field: 'url', widthGrow: 2.4, editor: 'input', cellEdited: onEdit, tooltip: true },
                { title: 'Group', field: 'group_title', widthGrow: 1.4,
                  editor: 'list', editorParams: { values: () => plGroups, autocomplete: true, allowEmpty: false, freetext: false }, cellEdited: onEdit },
                ...(showDeleted ? [{ title: 'Deleted', field: 'deleted', width: 64, hozAlign: 'center', headerSort: false,
                  formatter: c => `<input type="checkbox" ${c.getValue() ? 'checked' : ''} style="pointer-events:none">` }] : []),
                { title: '', field: '_a', width: 84, hozAlign: 'center', headerSort: false,
                  formatter: c => { const d = c.getRow().getData(); return `<span class="ple-act"><button data-a="move" title="Move to row">${ICON.move}</button><button data-a="edit" title="Edit">${ICON.edit}</button><button data-a="del" title="${d.deleted ? 'Restore' : 'Delete'}">${d.deleted ? ICON.restore : ICON.del}</button></span>`; } },
            ],
        });
        chTable.on('cellClick', (e, cell) => {
            const f = cell.getField(); const d = cell.getRow().getData();
            if (f === 'enabled') { const on = !d.enabled; J('/playlists/' + plId + '/channels/' + d.id, 'PATCH', { enabled: on }); cell.getRow().update({ enabled: on }); return; }
            if (f === 'deleted') { J('/playlists/' + plId + '/channels/' + d.id, 'DELETE', d.deleted ? { restore: true } : null).then(reloadChannels); return; }
            if (f !== '_a') return;
            const a = e.target.closest('button')?.dataset.a; if (!a) return;
            if (a === 'move') openMove(d);
            else if (a === 'edit') openEdit(d);
            else if (a === 'del') J('/playlists/' + plId + '/channels/' + d.id, 'DELETE', d.deleted ? { restore: true } : null).then(reloadChannels);
        });
        chTable.on('rowMoved', (row) => {
            const page = chTable.getPage() || 1;
            const globalRow = (page - 1) * SIZE + row.getPosition(true);
            J('/playlists/' + plId + '/channels/' + row.getData().id + '/move', 'POST', { row: globalRow }).then(reloadChannels);
        });
    }

    function reloadChannels() { if (plId) buildChannels(); } // rebuild = proven reliable filter/reorder path

    function toggleDeleted() {
        showDeleted = !showDeleted;
        $('ple-trash-toggle').classList.toggle('on', showDeleted);
        reloadChannels();
    }

    // move modal
    function openMove(d) { moveCid = d.id; $('ple-move-label').textContent = 'Move "' + (d.name || '') + '" to row #'; $('ple-move-row').value = d.row || 1; $('ple-move-overlay').classList.add('show'); }
    const closeMove = () => $('ple-move-overlay').classList.remove('show');
    function applyMove() { const row = Math.max(1, Number($('ple-move-row').value) || 1); J('/playlists/' + plId + '/channels/' + moveCid + '/move', 'POST', { row }).then(() => { closeMove(); reloadChannels(); }); }

    // edit / add modal (shared)
    function fillGroups(sel) { const g = $('ple-e-group'); g.innerHTML = ''; plGroups.forEach(t => { const o = document.createElement('option'); o.value = t; o.textContent = t; if (t === sel) o.selected = true; g.appendChild(o); }); }
    function openEdit(d) {
        editCid = d.id; editManual = !!d.manual;
        $('ple-edit-title').textContent = editManual ? 'Edit manual channel' : 'Edit channel';
        $('ple-e-name').value = d.name || ''; $('ple-e-url').value = d.url || ''; $('ple-e-logo').value = d.tvg_logo || '';
        $('ple-e-name').disabled = $('ple-e-url').disabled = $('ple-e-logo').disabled = ! editManual; // provider data is read-only here
        fillGroups(d.group_title); $('ple-e-err').textContent = '';
        $('ple-edit-overlay').classList.add('show');
    }
    function openAdd() {
        editCid = null; editManual = true;
        $('ple-edit-title').textContent = 'Add manual channel';
        $('ple-e-name').value = ''; $('ple-e-url').value = ''; $('ple-e-logo').value = '';
        $('ple-e-name').disabled = $('ple-e-url').disabled = $('ple-e-logo').disabled = false;
        fillGroups(groupFilter || plGroups[0]); $('ple-e-err').textContent = '';
        $('ple-edit-overlay').classList.add('show');
    }
    const closeEdit = () => $('ple-edit-overlay').classList.remove('show');
    async function saveEdit() {
        const group = $('ple-e-group').value;
        if (editCid === null) { // add manual
            const name = $('ple-e-name').value.trim(), url = $('ple-e-url').value.trim();
            if (!name || !url) { $('ple-e-err').textContent = 'Name and URL are required.'; return; }
            const { ok, data } = await J('/playlists/' + plId + '/channels', 'POST', { name, url, group, tvg_logo: $('ple-e-logo').value.trim() });
            if (!ok) { $('ple-e-err').textContent = data.message || 'Could not add.'; return; }
        } else {
            const body = { group_title: group };
            if (editManual) { body.name = $('ple-e-name').value; body.url = $('ple-e-url').value; body.tvg_logo = $('ple-e-logo').value; body.tvg_name = $('ple-e-name').value; }
            const { ok, data } = await J('/playlists/' + plId + '/channels/' + editCid, 'PATCH', body);
            if (!ok) { $('ple-e-err').textContent = data.message || 'Could not save.'; return; }
        }
        closeEdit(); loadGroups(); reloadChannels();
    }

    function onInput(e) {
        if (!e.target || !plId) return;
        if (e.target.id === 'ple-search') { clearTimeout(searchTimer); searchTimer = setTimeout(reloadChannels, 300); }
        else if (e.target.id === 'ple-gsearch' && grTable) { clearTimeout(gsearchTimer); gsearchTimer = setTimeout(() => { const v = e.target.value.trim(); v ? grTable.setFilter('group_title', 'like', v) : grTable.clearFilter(); }, 200); }
    }

    return { open, close, loadGroups, reloadChannels, toggleDeleted, openMove, closeMove, applyMove, openEdit, openAdd, closeEdit, saveEdit, onInput };
})();

if (!window.__GXPLE_BOUND) {
    window.__GXPLE_BOUND = true;
    document.addEventListener('input', e => window.GXPLE && window.GXPLE.onInput(e));
}
console.log('GXPLE playlist-editor {{ config('guidearr.version') }} loaded');
</script>
