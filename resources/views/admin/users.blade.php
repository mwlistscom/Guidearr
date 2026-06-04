@extends('admin.layout')
@section('title', 'Users')
@section('content')
<h1>Users</h1>

<div class="filters">
    <input type="search" id="uf-search" placeholder="Filter by name or email…" autocomplete="off">
    <select id="uf-status">
        <option value="all">All statuses</option>
        <option value="enabled">Enabled only</option>
        <option value="banned">Banned only</option>
    </select>
</div>

<table id="users-table">
    <thead>
        <tr><th>ID</th><th>Name</th><th>Email</th><th>Status</th><th>Role</th><th>Verified</th><th>Actions</th></tr>
    </thead>
    <tbody>
        @foreach ($users as $u)
            @php($enabled = $u->status === 'active')
            <tr data-name="{{ strtolower($u->name) }}" data-email="{{ strtolower($u->email) }}" data-enabled="{{ $enabled ? 1 : 0 }}">
                <td>{{ $u->id }}</td>
                <td>{{ $u->name }}</td>
                <td>{{ $u->email }}</td>
                <td><span class="badge {{ $enabled ? 'active' : 'banned' }}">{{ $enabled ? 'Enabled' : 'Banned' }}</span></td>
                <td>{{ $u->is_admin ? 'admin' : 'user' }}</td>
                <td>{{ $u->email_verified_at ? '✓' : '—' }}</td>
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
                    <form class="inline" method="POST" action="{{ route('admin.users.toggle', $u) }}">
                        @csrf @method('PATCH')
                        @if ($enabled)
                            <button class="icon on" title="Ban">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                            </button>
                        @else
                            <button class="icon off" title="Unban">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            </button>
                        @endif
                    </form>
                    <form class="inline" method="POST" action="{{ route('admin.users.destroy', $u) }}"
                          onsubmit="return confirm('Delete {{ $u->email }}?')">
                        @csrf @method('DELETE')
                        <button class="icon danger" title="Delete">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </form>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
<div id="no-match" class="empty" style="display:none">No users match your filter.</div>

<script>
(function () {
    var q = document.getElementById('uf-search');
    var sf = document.getElementById('uf-status');
    var rows = Array.prototype.slice.call(document.querySelectorAll('#users-table tbody tr'));
    var none = document.getElementById('no-match');
    function apply() {
        var term = q.value.trim().toLowerCase();
        var st = sf.value, shown = 0;
        rows.forEach(function (tr) {
            var matchText = !term || tr.dataset.name.indexOf(term) !== -1 || tr.dataset.email.indexOf(term) !== -1;
            var matchSt = st === 'all'
                || (st === 'enabled' && tr.dataset.enabled === '1')
                || (st === 'banned' && tr.dataset.enabled === '0');
            var show = matchText && matchSt;
            tr.style.display = show ? '' : 'none';
            if (show) shown++;
        });
        none.style.display = shown ? 'none' : '';
    }
    q.addEventListener('input', apply);
    sf.addEventListener('change', apply);
})();
</script>
@endsection
