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

<script>
window.GXPL = (function () {
    const $ = id => document.getElementById(id);
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
    };
    let table = null;

    function init() {
        const el = $('playlist-grid');
        if (!el || !window.Tabulator) return;
        if (table) { try { table.destroy(); } catch (e) {} table = null; }
        table = new Tabulator(el, {
            layout: 'fitColumns', maxHeight: '70vh', placeholder: 'No playlists yet — use + to create one.',
            ajaxURL: '{{ route('playlists.data') }}',
            columns: [
                { title: 'Name', field: 'name', widthGrow: 3 },
                { title: 'Key', field: 'cipher', widthGrow: 2, formatter: c => `<span class="pl-key">${esc(c.getValue())}</span>` },
                { title: 'First Ch #', field: 'channel_start', width: 100, hozAlign: 'right' },
                { title: 'Channels', field: 'channels', width: 100, hozAlign: 'right' },
                { title: 'Groups', field: 'groups', width: 90, hozAlign: 'right' },
                { title: 'Enabled', field: 'enabled', width: 90, hozAlign: 'center',
                  formatter: c => `<input type="checkbox" ${c.getValue() ? 'checked' : ''} style="pointer-events:none">` },
                { title: 'Actions', field: '_act', width: 130, hozAlign: 'center', headerSort: false,
                  formatter: () => `<span class="pl-act">
                        <button data-a="links" title="Links">${ICON.link}</button>
                        <button data-a="edit" title="Edit playlist">${ICON.edit}</button>
                        <button data-a="del" class="danger" title="Delete">${ICON.del}</button></span>`,
                  cellClick: (e, c) => {
                        const a = e.target.closest('button')?.dataset.a; if (!a) return;
                        const d = c.getRow().getData();
                        if (a === 'del') del(d.id, d.name);
                        else alert('The playlist editor (' + a + ') lands in the next build.');
                  } },
            ],
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

    async function del(id, name) {
        if (!confirm('Delete playlist "' + (name || '') + '"? This removes its channel list (providers are untouched).')) return;
        await J('/playlists/' + id, 'DELETE');
        reload();
    }

    return { init, reload, openCreate, closeCreate, create, del };
})();

if (!window.__GXPL_BOUND) {
    window.__GXPL_BOUND = true;
    document.addEventListener('livewire:navigated', () => window.GXPL && window.GXPL.init());
    document.addEventListener('DOMContentLoaded', () => window.GXPL && window.GXPL.init());
}
console.log('GXPL playlists {{ config('guidearr.version') }} loaded');
window.GXPL.init();
</script>
