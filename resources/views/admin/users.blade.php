@extends('admin.layout')
@section('title', 'Users')
@section('content')
<h1>Users</h1>

<div class="usertop">
    <div class="filters">
        <input type="search" id="uf-search" placeholder="Filter by name or email…" autocomplete="off">
        <select id="uf-status">
            <option value="all">All statuses</option>
            <option value="enabled">Enabled only</option>
            <option value="banned">Banned only</option>
            <option value="online">Online only</option>
        </select>
    </div>
    <a class="addbtn" href="{{ route('admin.users.create') }}">+ Add user</a>
</div>
<style>
    .usertop { display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; }
    .usertop .filters { margin:0; }
    .addbtn { background:var(--accent); color:#1a1205; font-weight:700; border:none; border-radius:.5rem;
        padding:.5rem .9rem; text-decoration:none; white-space:nowrap; }
    .addbtn:hover { filter:brightness(1.1); }
    /* Small provider badge in the Role column marking a linked social account. */
    .prov-badge { display:inline-flex; align-items:center; justify-content:center; width:1.05rem; height:1.05rem;
        border-radius:50%; font-size:.6rem; font-weight:700; color:#fff; margin-left:.3rem; line-height:1;
        vertical-align:middle; }
    .prov-badge.google { background:#4285f4; }
    .prov-badge.facebook { background:#1877f2; }
    td.role-cell { white-space:nowrap; }
    /* Keep the action icons on a single row (don't wrap onto two lines). */
    #users-table td.actions { flex-wrap:nowrap; white-space:nowrap; }
    #users-table th:last-child, #users-table td.actions { width:1%; }
    /* Currently-logged-in indicator. */
    .online-dot { display:inline-block; width:.6rem; height:.6rem; border-radius:50%;
        background:transparent; box-shadow:inset 0 0 0 1.5px var(--muted, #888); }
    .online-dot.on { background:#22c55e; box-shadow:0 0 0 2px rgba(34,197,94,.25); }
    /* Sortable headers. */
    #users-table th.sortable { cursor:pointer; user-select:none; white-space:nowrap; }
    #users-table th.sortable:hover { color:var(--accent); }
    #users-table th.sortable .arr { opacity:.4; font-size:.7em; }
    #users-table th.sortable.sorted .arr { opacity:1; }
    td.when { white-space:nowrap; font-variant-numeric:tabular-nums; }
    /* Last login shown as the registration date because no sign-in has been recorded yet. */
    td.when.fallback { color:var(--muted, #888); font-style:italic; }
    /* Pager. */
    .pager { display:flex; align-items:center; gap:.5rem; margin-top:1rem; flex-wrap:wrap; }
    .pager .info { color:var(--muted, #999); font-size:.9rem; margin-right:auto; }
    .pager button { background:var(--panel, #2a2a2a); color:inherit; border:1px solid var(--border, #444);
        border-radius:.4rem; padding:.3rem .6rem; cursor:pointer; min-width:2rem; }
    .pager button:hover:not(:disabled) { border-color:var(--accent); }
    .pager button.cur { background:var(--accent); color:#1a1205; font-weight:700; border-color:var(--accent); }
    .pager button:disabled { opacity:.4; cursor:default; }
    /* Sign-in method icons. */
    .login-cell { white-space:nowrap; }
    .loginico { display:inline-flex; align-items:center; justify-content:center; width:1.15rem; height:1.15rem;
        border-radius:50%; font-size:.66rem; font-weight:700; color:#fff; line-height:1; }
    .loginico.google { background:#4285f4; }
    .loginico.facebook { background:#1877f2; }
    .loginico.pw { background:#3a3d44; }
    /* Ban/unban toggle switch (checked = enabled/active). */
    .switch { position:relative; display:inline-block; width:2.4rem; height:1.3rem; vertical-align:middle; }
    .switch input { opacity:0; width:0; height:0; }
    .switch .slider { position:absolute; inset:0; cursor:pointer; background:#dc2626; border-radius:999px; transition:background .15s; }
    .switch .slider:before { content:""; position:absolute; height:1rem; width:1rem; left:.15rem; bottom:.15rem;
        background:#fff; border-radius:50%; transition:transform .15s; }
    .switch input:checked + .slider { background:#22c55e; }
    .switch input:checked + .slider:before { transform:translateX(1.1rem); }
    .switch input:disabled + .slider { opacity:.45; cursor:not-allowed; }
</style>

<table id="users-table">
    <thead>
        <tr>
            <th class="sortable" data-key="online" data-type="num" title="Currently logged in">● <span class="arr"></span></th>
            <th class="sortable" data-key="id" data-type="num">ID <span class="arr"></span></th>
            <th class="sortable" data-key="name" data-type="text">Name <span class="arr"></span></th>
            <th class="sortable" data-key="email" data-type="text">Email <span class="arr"></span></th>
            <th class="sortable" data-key="login" data-type="text" title="Sign-in method">Login <span class="arr"></span></th>
            <th class="sortable" data-key="status" data-type="text">Status <span class="arr"></span></th>
            <th class="sortable" data-key="role" data-type="text">Role <span class="arr"></span></th>
            <th class="sortable" data-key="playlists" data-type="num">Playlists <span class="arr"></span></th>
            <th class="sortable" data-key="verified" data-type="num">Verified <span class="arr"></span></th>
            <th class="sortable" data-key="registered" data-type="num">Registered <span class="arr"></span></th>
            <th class="sortable" data-key="lastlogin" data-type="num">Last login <span class="arr"></span></th>
            <th>Actions</th>
        </tr>
    </thead>
    @php($activeAdmins = $users->where('is_admin', true)->where('status', 'active')->count())
    <tbody>
        @foreach ($users as $u)
            @php($enabled = $u->status === 'active')
            @php($online = isset($onlineIds[$u->id]))
            @php($socials = $u->socialAccounts->pluck('provider')->unique()->values())
            @php($loginKey = $socials->first() ?? ($u->password ? 'password' : 'none'))
            @php($isSelf = auth()->id() === $u->id)
            @php($lockToggle = $enabled && ($isSelf || ($u->is_admin && $activeAdmins <= 1)))
            <tr
                data-id="{{ $u->id }}"
                data-name="{{ strtolower($u->name) }}"
                data-email="{{ strtolower($u->email) }}"
                data-login="{{ $loginKey }}"
                data-status="{{ $enabled ? 'enabled' : 'banned' }}"
                data-role="{{ $u->is_admin ? 'admin' : 'user' }}"
                data-playlists="{{ $u->playlists_count }}"
                data-verified="{{ $u->email_verified_at ? 1 : 0 }}"
                data-registered="{{ optional($u->created_at)->timestamp ?? 0 }}"
                data-lastlogin="{{ optional($u->last_login_at ?? $u->created_at)->timestamp ?? 0 }}"
                data-online="{{ $online ? 1 : 0 }}"
                data-enabled="{{ $enabled ? 1 : 0 }}">
                <td><span class="online-dot {{ $online ? 'on' : '' }}"
                    title="{{ $online ? "Active in the last {$onlineWindow} min" : 'No active session' }}"></span></td>
                <td>{{ $u->id }}</td>
                <td>{{ $u->name }}</td>
                <td>{{ $u->email }}</td>
                <td class="login-cell">@if ($socials->contains('google'))<span class="loginico google" title="Google sign-in">G</span>@endif @if ($socials->contains('facebook'))<span class="loginico facebook" title="Facebook sign-in">F</span>@endif @if ($socials->isEmpty())@if ($u->password)<span class="loginico pw" title="Email &amp; password">@</span>@else<span class="muted" title="No sign-in method set">—</span>@endif @endif</td>
                <td>
                    <form class="inline" method="POST" action="{{ route('admin.users.toggle', $u) }}">
                        @csrf @method('PATCH')
                        <label class="switch" title="{{ $enabled ? ($lockToggle ? 'Cannot ban this account' : 'Enabled — switch off to ban') : 'Banned — switch on to unban' }}">
                            <input type="checkbox" {{ $enabled ? 'checked' : '' }} {{ $lockToggle ? 'disabled' : '' }}
                                onchange="if(!this.checked){ if(confirm('Ban {{ $u->email }}? They will be signed out and added to the ban list.')){this.form.submit();}else{this.checked=true;} } else { this.form.submit(); }">
                            <span class="slider"></span>
                        </label>
                    </form>
                </td>
                <td class="role-cell">{{ $u->is_admin ? 'admin' : 'user' }}</td>
                <td>{{ $u->playlists_count }}</td>
                <td>{{ $u->email_verified_at ? '✓' : '—' }}</td>
                <td class="when">{{ optional($u->created_at)->format('Y-m-d') ?? '—' }}</td>
                <td class="when {{ $u->last_login_at ? '' : 'fallback' }}"
                    @unless ($u->last_login_at) title="No sign-in recorded yet — showing registration date" @endunless>{{ optional($u->last_login_at ?? $u->created_at)->format('Y-m-d H:i') ?? '—' }}</td>
                <td class="actions">
                    <a class="icon" href="{{ route('admin.users.edit', $u) }}" title="Edit">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    </a>
                    <form class="inline" method="POST" action="{{ route('admin.users.verify', $u) }}">
                        @csrf @method('PATCH')
                        @if ($u->email_verified_at)
                            <button class="icon on" title="Mark unverified">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            </button>
                        @else
                            <button class="icon off" title="Mark verified">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            </button>
                        @endif
                    </form>
                    <form class="inline" method="POST" action="{{ route('admin.users.destroy', $u) }}">
                        @csrf @method('DELETE')
                        <input type="hidden" name="ban" value="0">
                        <button class="icon danger" title="Delete user + all their playlists"
                                onclick="return userDelete(this, '{{ $u->email }}')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </form>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
<div id="no-match" class="empty" style="display:none">No users match your filter.</div>
<div class="pager" id="users-pager"></div>

<script>
(function () {
    var PAGE_SIZE = 25;
    var tbody = document.querySelector('#users-table tbody');
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    var q = document.getElementById('uf-search');
    var sf = document.getElementById('uf-status');
    var none = document.getElementById('no-match');
    var pager = document.getElementById('users-pager');
    var state = { key: 'id', dir: 'desc', page: 1 };

    function filtered() {
        var term = q.value.trim().toLowerCase(), st = sf.value;
        return rows.filter(function (tr) {
            var okText = !term || tr.dataset.name.indexOf(term) !== -1 || tr.dataset.email.indexOf(term) !== -1;
            var okSt = st === 'all'
                || (st === 'enabled' && tr.dataset.enabled === '1')
                || (st === 'banned' && tr.dataset.enabled === '0')
                || (st === 'online' && tr.dataset.online === '1');
            return okText && okSt;
        });
    }

    function sortRows(list) {
        var k = state.key, type = document.querySelector('#users-table th[data-key="' + k + '"]').dataset.type;
        var sign = state.dir === 'asc' ? 1 : -1;
        return list.slice().sort(function (a, b) {
            var av = a.dataset[k], bv = b.dataset[k], c;
            if (type === 'num') { c = (parseFloat(av) || 0) - (parseFloat(bv) || 0); }
            else { c = av < bv ? -1 : av > bv ? 1 : 0; }
            if (c === 0) { c = (parseFloat(a.dataset.id) || 0) - (parseFloat(b.dataset.id) || 0); } // stable tie-break
            return c * sign;
        });
    }

    function render() {
        var list = sortRows(filtered());
        var total = list.length;
        var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
        if (state.page > pages) state.page = pages;
        var start = (state.page - 1) * PAGE_SIZE;
        var pageRows = list.slice(start, start + PAGE_SIZE);

        rows.forEach(function (tr) { tr.style.display = 'none'; });
        pageRows.forEach(function (tr) { tr.style.display = ''; tbody.appendChild(tr); }); // reorder + show
        none.style.display = total ? 'none' : '';

        document.querySelectorAll('#users-table th.sortable').forEach(function (th) {
            var on = th.dataset.key === state.key;
            th.classList.toggle('sorted', on);
            th.querySelector('.arr').textContent = on ? (state.dir === 'asc' ? '▲' : '▼') : '';
        });
        renderPager(total, pages, start, pageRows.length);
    }

    function renderPager(total, pages, start, shown) {
        pager.innerHTML = '';
        var info = document.createElement('span');
        info.className = 'info';
        info.textContent = total ? ('Showing ' + (start + 1) + '–' + (start + shown) + ' of ' + total) : 'No users';
        pager.appendChild(info);
        if (pages <= 1) return;
        function btn(label, page, opts) {
            opts = opts || {};
            var b = document.createElement('button');
            b.textContent = label;
            if (opts.cur) b.className = 'cur';
            if (opts.disabled) b.disabled = true;
            else b.addEventListener('click', function () { state.page = page; render(); });
            pager.appendChild(b);
        }
        btn('‹', state.page - 1, { disabled: state.page === 1 });
        // windowed page numbers around the current page
        var from = Math.max(1, state.page - 2), to = Math.min(pages, state.page + 2);
        if (from > 1) btn('1', 1, { cur: state.page === 1 });
        if (from > 2) { var e = document.createElement('span'); e.textContent = '…'; pager.appendChild(e); }
        for (var p = from; p <= to; p++) btn(String(p), p, { cur: p === state.page });
        if (to < pages - 1) { var e2 = document.createElement('span'); e2.textContent = '…'; pager.appendChild(e2); }
        if (to < pages) btn(String(pages), pages, { cur: state.page === pages });
        btn('›', state.page + 1, { disabled: state.page === pages });
    }

    document.querySelectorAll('#users-table th.sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            var k = th.dataset.key;
            if (state.key === k) { state.dir = state.dir === 'asc' ? 'desc' : 'asc'; }
            else { state.key = k; state.dir = (th.dataset.type === 'num') ? 'desc' : 'asc'; }
            state.page = 1;
            render();
        });
    });
    q.addEventListener('input', function () { state.page = 1; render(); });
    sf.addEventListener('change', function () { state.page = 1; render(); });
    render();
})();

// Delete a user: confirm the delete, then ask whether to also ban the email so they can't re-register.
function userDelete(btn, email) {
    if (!confirm('Delete ' + email + ' and all their playlists and providers? This cannot be undone.')) {
        return false;
    }
    btn.form.querySelector('input[name=ban]').value =
        confirm('Also add ' + email + ' to the ban list so they cannot re-register?') ? '1' : '0';
    return true;
}
</script>
@endsection
