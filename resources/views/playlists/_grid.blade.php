{{-- Playlists list: Tabulator + create (seed from providers) + delete.
     wire:navigate-safe — assets load once, controller reinstalls + binds-once each navigation. --}}

@assets
<link href="https://cdnjs.cloudflare.com/ajax/libs/tabulator/6.3.1/css/tabulator_midnight.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/tabulator/6.3.1/js/tabulator.min.js"></script>
@endassets

<meta name="csrf-token" content="{{ csrf_token() }}">

<style>
    .pl-wrap { --pl-accent:#f47521; }
    .pl-toolbar { display:flex; gap:.5rem; align-items:center; margin-bottom:.7rem; }
    .pl-toolbar button { background:#1c1d21; border:1px solid rgba(255,255,255,.14); color:#cdd2da;
        border-radius:.5rem; padding:.4rem .55rem; cursor:pointer; line-height:0; }
    .pl-toolbar button:hover { color:#fff; border-color:var(--pl-accent); }
    .pl-toolbar button svg { width:18px; height:18px; }
    .pl-key { color:#8ab4f8; font-family:ui-monospace,monospace; font-size:.82rem; }
    .pl-act { display:inline-flex; gap:.45rem; }
    .pl-act button { background:transparent; border:none; color:#aab; cursor:pointer; padding:.2rem; border-radius:.35rem; line-height:0; }
    .pl-act button:hover { color:#fff; background:rgba(255,255,255,.08); }
    .pl-act button.danger:hover { color:#f87171; background:rgba(248,113,113,.12); }
    .pl-act svg { width:15px; height:15px; }
    .pl-overlay { position:fixed; inset:0; background:rgba(0,0,0,.6); display:none; align-items:flex-start;
        justify-content:center; padding-top:6vh; z-index:60; }
    .pl-overlay.show { display:flex; }
    .pl-link-row { margin:.55rem 0 .9rem; }
    .pl-link-row label { display:block; font-size:.8rem; color:#9aa0aa; margin-bottom:.25rem; }
    .pl-link-line { display:flex; gap:.5rem; align-items:stretch; }
    .pl-link-line input { flex:1; min-width:0; padding:.45rem .55rem; border-radius:.45rem; border:1px solid rgba(255,255,255,.16);
        background:#16171a; color:#e6e7ea; font-family:ui-monospace,monospace; font-size:.78rem; }
    .pl-copy { background:var(--pl-accent,#f47521); border:none; color:#10120f; font-weight:700; border-radius:.45rem;
        padding:0 .8rem; cursor:pointer; white-space:nowrap; }
    .pl-copy.copied { background:#3fb950; }
    .pl-links-unset { font-size:.85rem; color:#cdd2da; line-height:1.5; background:rgba(244,117,33,.08);
        border:1px solid rgba(244,117,33,.3); border-radius:.5rem; padding:.7rem .8rem; }
    .pl-modal { background:#1b1c20; border:1px solid rgba(255,255,255,.14); border-radius:.8rem; width:100%;
        max-width:30rem; padding:1.3rem; color:#e6e7ea; max-height:84vh; overflow:auto; }
    .pl-modal h2 { font-size:1.1rem; font-weight:800; margin-bottom:1rem; }
    .pl-field { margin-bottom:.8rem; }
    .pl-field label { display:block; font-size:.8rem; color:#aab; margin-bottom:.25rem; }
    .pl-field input[type=text], .pl-field input[type=number], .pl-field select {
        width:100%; box-sizing:border-box; background:#0e0f13; border:1px solid rgba(255,255,255,.16);
        color:#e6e7ea; border-radius:.5rem; padding:.45rem .6rem; font-size:.9rem; }
    .pl-field select[multiple] { min-height:7rem; }
    .pl-check { display:flex; align-items:center; gap:.5rem; font-size:.88rem; color:#cdd2da; }
    .pl-hint { font-size:.76rem; color:#8a8f98; margin-top:.25rem; }
    .pl-err { color:#f87171; font-size:.82rem; min-height:1em; }
    .pl-actions { display:flex; justify-content:flex-end; gap:.6rem; margin-top:1.1rem; }
    .pl-btn { background:var(--pl-accent); color:#fff; border:none; border-radius:.5rem; padding:.5rem 1rem;
        font-weight:700; cursor:pointer; font-size:.88rem; }
    .pl-btn.secondary { background:#2a2c31; color:#cdd2da; }
    .pl-btn.danger-btn { background:#dc2626; }
    .pl-btn.danger-btn:hover { background:#ef4444; }
</style>

<div class="pl-wrap">
    <div class="pl-toolbar">
        <button title="New playlist" onclick="GXPL.openCreate()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
        </button>
        <button title="Refresh" onclick="GXPL.reload()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        </button>
    </div>
    <div id="playlist-grid"></div>
</div>

<div class="pl-overlay" id="pl-create-overlay">
    <div class="pl-modal">
        <h2>New playlist</h2>
        <div class="pl-field"><label>Name *</label><input type="text" id="pl-name" placeholder="My Playlist"></div>
        <div class="pl-field"><label>IP lock (optional)</label><input type="text" id="pl-iplock" placeholder="leave blank for none"></div>
        <div class="pl-field"><label>First channel #</label><input type="number" id="pl-chstart" value="100" min="1"></div>
        <div class="pl-field"><label class="pl-check"><input type="checkbox" id="pl-extgrp" checked> Emit #EXTGRP group tags</label></div>
        <div class="pl-field">
            <label>Providers to include</label>
            <select id="pl-providers" multiple></select>
            <div class="pl-hint">All channels &amp; groups from the selected providers are added (you curate afterwards).</div>
        </div>
        <div class="pl-field">
            <label>TV guide source</label>
            <select id="pl-guide"><option value="">No guide</option></select>
            <div class="pl-hint">A single provider's EPG (overlapping guides cause conflicts).</div>
        </div>
        <div class="pl-err" id="pl-create-err"></div>
        <div class="pl-actions">
            <button class="pl-btn secondary" onclick="GXPL.closeCreate()">Cancel</button>
            <button class="pl-btn" id="pl-create-btn" onclick="GXPL.create()">Create</button>
        </div>
    </div>
</div>

<div class="pl-overlay" id="pl-edit-overlay">
    <div class="pl-modal">
        <h2>Edit playlist</h2>
        <input type="hidden" id="pl-e-id">
        <div class="pl-field"><label>Name *</label><input type="text" id="pl-e-name"></div>
        <div class="pl-field"><label>IP lock (optional)</label><input type="text" id="pl-e-iplock" placeholder="leave blank for none"></div>
        <div class="pl-field"><label>First channel #</label><input type="number" id="pl-e-chstart" min="1"></div>
        <div class="pl-field"><label class="pl-check"><input type="checkbox" id="pl-e-extgrp"> Emit #EXTGRP group tags</label></div>
        <div class="pl-field"><label class="pl-check"><input type="checkbox" id="pl-e-enabled"> Enabled</label></div>
        <div class="pl-field">
            <label>TV guide source</label>
            <select id="pl-e-guide"><option value="">No guide</option></select>
        </div>
        <div class="pl-field">
            <label>Encryption key (read-only)</label>
            <input type="text" id="pl-e-key" disabled>
            <div class="pl-hint">Use the key icon in the Actions column to generate a new one.</div>
        </div>
        <div class="pl-err" id="pl-e-err"></div>
        <div class="pl-actions">
            <button class="pl-btn secondary" onclick="GXPL.closeEdit()">Cancel</button>
            <button class="pl-btn" onclick="GXPL.saveEdit()">Save</button>
        </div>
    </div>
</div>

<div class="pl-overlay" id="pl-confirm-overlay">
    <div class="pl-modal" style="max-width:24rem">
        <h2 id="pl-confirm-title">Confirm</h2>
        <p id="pl-confirm-msg" style="font-size:.9rem;color:#cdd2da;line-height:1.5;margin-bottom:.4rem"></p>
        <div class="pl-actions">
            <button class="pl-btn secondary" onclick="GXPL.closeConfirm()">Cancel</button>
            <button class="pl-btn" id="pl-confirm-btn" onclick="GXPL.runConfirm()">Confirm</button>
        </div>
    </div>
</div>

<div class="pl-overlay" id="pl-links-overlay">
    <div class="pl-modal" style="max-width:34rem">
        <h2>Playlist Links — <span id="pl-links-name"></span></h2>
        <div id="pl-links-body"></div>
        <div id="pl-links-unset" class="pl-links-unset" hidden>
            The public links base URL hasn't been set yet. An admin can set it under
            <strong>Admin → Status → Playlist links</strong>.
        </div>
        <div class="pl-actions">
            <button class="pl-btn secondary" onclick="GXPL.closeLinks()">Close</button>
        </div>
    </div>
</div>

<script>
window.GXPL = (function () {
    const $ = id => document.getElementById(id);
    const LINKS_BASE = @json(\App\Support\Settings::linksBaseUrl());
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
    const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[m]));
    const ICON = {
        link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
        del:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        key:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M11.4 11.4 21 1.8"/><path d="m16 6 3 3"/><path d="m18.5 3.5 3 3"/></svg>',
    };
    let table = null;

    function init() {
        const el = $('playlist-grid');
        if (!el || !window.Tabulator) return;
        if (table) { try { table.destroy(); } catch (e) {} table = null; }
        const onEdit = (cell) => {
            const f = cell.getField(); const body = {}; body[f] = cell.getValue();
            J('/playlists/' + cell.getRow().getData().id, 'PATCH', body);
        };
        table = new Tabulator(el, {
            layout: 'fitColumns', maxHeight: '70vh', placeholder: 'No playlists yet — use + to create one.',
            editTriggerEvent: 'dblclick',
            ajaxURL: '{{ route('playlists.data') }}',
            columns: [
                { title: 'Name', field: 'name', widthGrow: 3, editor: 'input', cellEdited: onEdit },
                { title: 'Key', field: 'cipher', widthGrow: 2, formatter: c => `<span class="pl-key">${esc(c.getValue())}</span>` },
                { title: 'First Ch #', field: 'channel_start', width: 100, hozAlign: 'right', editor: 'number', cellEdited: onEdit },
                { title: 'Channels', field: 'channels', width: 100, hozAlign: 'right' },
                { title: 'Groups', field: 'groups', width: 90, hozAlign: 'right' },
                { title: 'Enabled', field: 'enabled', width: 90, hozAlign: 'center',
                  formatter: c => `<input type="checkbox" ${c.getValue() ? 'checked' : ''} style="pointer-events:none">` },
                { title: 'Actions', field: '_act', width: 158, hozAlign: 'center', headerSort: false,
                  formatter: () => `<span class="pl-act">
                        <button data-a="links" title="Links">${ICON.link}</button>
                        <button data-a="key" title="Generate new encryption key">${ICON.key}</button>
                        <button data-a="edit" title="Edit playlist settings">${ICON.edit}</button>
                        <button data-a="del" class="danger" title="Delete playlist">${ICON.del}</button></span>`,
                  cellClick: (e, c) => {
                        const a = e.target.closest('button')?.dataset.a; if (!a) return;
                        const d = c.getRow().getData();
                        if (a === 'del') confirmDelete(d);
                        else if (a === 'key') confirmRotateKey(d);
                        else if (a === 'edit') openEdit(d);
                        else if (a === 'links') openLinks(d);
                  } },
            ],
        });
        table.on('rowClick', (e, row) => {   // select a playlist to open its editor below
            if (e.target.closest('button') || e.target.closest('input') || e.target.closest('.tabulator-editing')) return;
            const d = row.getData();
            window.GXPLE && window.GXPLE.open(d.id, d.name);
        });
    }

    const reload = () => table && table.setData('{{ route('playlists.data') }}');

    async function openCreate() {
        $('pl-name').value = ''; $('pl-iplock').value = ''; $('pl-chstart').value = '100';
        $('pl-extgrp').checked = true; $('pl-create-err').textContent = '';
        const { data } = await J('{{ route('playlists.options') }}');
        const provs = (data && data.providers) || [];
        $('pl-providers').innerHTML = provs.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
        $('pl-guide').innerHTML = '<option value="">No guide</option>' + provs.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
        $('pl-create-overlay').classList.add('show');
    }
    const closeCreate = () => $('pl-create-overlay').classList.remove('show');

    async function create() {
        const name = $('pl-name').value.trim();
        if (!name) { $('pl-create-err').textContent = 'Name is required.'; return; }
        const providers = Array.from($('pl-providers').selectedOptions).map(o => Number(o.value));
        const btn = $('pl-create-btn'); btn.disabled = true; btn.textContent = 'Creating…';
        const { ok, data } = await J('{{ route('playlists.store') }}', 'POST', {
            name,
            iplock: $('pl-iplock').value.trim(),
            channel_start: Number($('pl-chstart').value) || 100,
            extgrp_tags: $('pl-extgrp').checked,
            guide_provider_id: Number($('pl-guide').value) || null,
            providers,
        });
        btn.disabled = false; btn.textContent = 'Create';
        if (!ok) { $('pl-create-err').textContent = data.message || 'Could not create playlist.'; return; }
        closeCreate(); reload();
    }

    // ---- confirm overlay (delete + key rotation) ----
    let confirmFn = null;
    function confirmAction(title, message, btnLabel, danger, fn) {
        confirmFn = fn;
        $('pl-confirm-title').textContent = title;
        $('pl-confirm-msg').textContent = message;
        const b = $('pl-confirm-btn'); b.textContent = btnLabel; b.classList.toggle('danger-btn', !!danger);
        $('pl-confirm-overlay').classList.add('show');
    }
    const closeConfirm = () => { $('pl-confirm-overlay').classList.remove('show'); confirmFn = null; };
    async function runConfirm() { const fn = confirmFn; closeConfirm(); if (fn) await fn(); }

    function confirmDelete(d) {
        confirmAction('Delete playlist', 'Delete “' + (d.name || '') + '”? This permanently removes the playlist and its channel-list file. Your providers are untouched.', 'Delete', true,
            async () => { await J('/playlists/' + d.id, 'DELETE'); reload(); });
    }
    function openLinks(d) {
        $('pl-links-name').textContent = d.name || '';
        const base = (LINKS_BASE || '').replace(/\/+$/, '');
        const body = $('pl-links-body'); const unset = $('pl-links-unset');
        if (!base) { body.innerHTML = ''; body.hidden = true; unset.hidden = false; $('pl-links-overlay').classList.add('show'); return; }
        unset.hidden = true; body.hidden = false;
        const key = encodeURIComponent(d.cipher || '');
        const links = [
            ['M3U Link', base + '/m3u?key=' + key],
            ['EPG / Guide Link', base + '/epg?key=' + key],
            ['Stream Link', base + '/strm?key=' + key],
        ];
        body.innerHTML = links.map(([label, url]) => `
            <div class="pl-link-row">
                <label>${esc(label)}</label>
                <div class="pl-link-line">
                    <input type="text" readonly value="${esc(url)}" onclick="this.select()">
                    <button class="pl-copy" type="button" data-url="${esc(url)}">Copy</button>
                </div>
            </div>`).join('');
        body.querySelectorAll('.pl-copy').forEach(btn => btn.addEventListener('click', async () => {
            const url = btn.dataset.url;
            try { await navigator.clipboard.writeText(url); }
            catch (e) { const i = btn.previousElementSibling; i.select(); document.execCommand('copy'); }
            btn.classList.add('copied'); const t = btn.textContent; btn.textContent = 'Copied';
            setTimeout(() => { btn.classList.remove('copied'); btn.textContent = t; }, 1200);
        }));
        $('pl-links-overlay').classList.add('show');
    }
    const closeLinks = () => $('pl-links-overlay').classList.remove('show');

    function confirmRotateKey(d) {
        confirmAction('Generate new key', 'Generate a new encryption key for “' + (d.name || '') + '”? The current key (' + (d.cipher || '') + ') stops working immediately and any URLs using it will break.', 'Generate new key', false,
            async () => { await J('/playlists/' + d.id + '/rotate-key', 'POST', {}); reload(); });
    }

    // ---- edit settings overlay (everything except the key) ----
    async function openEdit(d) {
        $('pl-e-id').value = d.id;
        $('pl-e-name').value = d.name || '';
        $('pl-e-iplock').value = d.iplock || '';
        $('pl-e-chstart').value = d.channel_start || 100;
        $('pl-e-extgrp').checked = !!d.extgrp_tags;
        $('pl-e-enabled').checked = !!d.enabled;
        $('pl-e-key').value = d.cipher || '';
        $('pl-e-err').textContent = '';
        const { data } = await J('{{ route('playlists.options') }}');
        const provs = (data && data.providers) || [];
        $('pl-e-guide').innerHTML = '<option value="">No guide</option>' + provs.map(p => `<option value="${p.id}" ${Number(d.guide_provider_id) === p.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('');
        $('pl-edit-overlay').classList.add('show');
    }
    const closeEdit = () => $('pl-edit-overlay').classList.remove('show');
    async function saveEdit() {
        const id = $('pl-e-id').value;
        const name = $('pl-e-name').value.trim();
        if (!name) { $('pl-e-err').textContent = 'Name is required.'; return; }
        const { ok, data } = await J('/playlists/' + id, 'PATCH', {
            name,
            iplock: $('pl-e-iplock').value.trim(),
            channel_start: Number($('pl-e-chstart').value) || 100,
            extgrp_tags: $('pl-e-extgrp').checked,
            enabled: $('pl-e-enabled').checked,
            guide_provider_id: Number($('pl-e-guide').value) || null,
        });
        if (!ok) { $('pl-e-err').textContent = data.message || 'Could not save.'; return; }
        closeEdit(); reload();
    }

    return { init, reload, openCreate, closeCreate, create, openEdit, closeEdit, saveEdit, confirmDelete, confirmRotateKey, closeConfirm, runConfirm, openLinks, closeLinks };
})();

if (!window.__GXPL_BOUND) {
    window.__GXPL_BOUND = true;
    document.addEventListener('livewire:navigated', () => window.GXPL && window.GXPL.init());
    document.addEventListener('DOMContentLoaded', () => window.GXPL && window.GXPL.init());
}
console.log('GXPL playlists {{ config('guidearr.version') }} loaded');
window.GXPL.init();
</script>
