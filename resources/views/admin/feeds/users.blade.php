@extends('admin.layout')
@section('title', 'Feeds')
@section('content')
<h1>Feeds</h1>
<p style="color:var(--muted);margin-bottom:1rem">Browse ingested provider data by user, then drill into a provider's channels.</p>

<table class="grid">
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

<style>
    table.grid { width:100%; border-collapse:collapse; font-size:.9rem; }
    table.grid th, table.grid td { text-align:left; padding:.55rem .7rem; border-bottom:1px solid rgba(255,255,255,.08); }
    table.grid th { color:var(--muted); font-weight:600; }
    .btn { display:inline-block; background:#26272b; color:#e6e7ea; border:1px solid rgba(255,255,255,.14);
        border-radius:.45rem; padding:.3rem .7rem; font-size:.82rem; text-decoration:none; }
    .btn:hover { filter:brightness(1.15); }
    .btn.accent { background:var(--accent); color:#1a1205; border:none; font-weight:700; }
    .crumb { color:var(--muted); margin-bottom:1rem; font-size:.9rem; }
    .crumb a { color:var(--accent); text-decoration:none; }
</style>
@endsection
