@extends('admin.layout')
@section('title', 'Feeds')
@section('content')
<h1>Feeds</h1>
<p style="color:var(--muted);margin-bottom:1rem">The job queue and ingested provider data. Drill into a user to browse a provider's channels.</p>

<h2 style="font-size:1.05rem;font-weight:700;margin:.4rem 0 .6rem">Job Queue</h2>
<table class="grid" style="margin-bottom:2rem">
    <thead><tr><th>Provider</th><th>User</th><th style="width:6rem">Type</th><th style="width:8rem">State</th><th style="width:6rem">Attempts</th><th style="width:6rem">Errors</th><th style="width:12rem">Updated</th></tr></thead>
    <tbody>
    @forelse ($queue as $j)
        <tr>
            <td>{{ $j->provider->name ?? '#' . $j->provider_id }}</td>
            <td style="color:var(--muted)">{{ $j->user->email ?? '—' }}</td>
            <td>{{ strtoupper($j->type) }}</td>
            <td><span class="qstate q-{{ $j->state }}">{{ strtoupper($j->state) }}</span></td>
            <td>{{ $j->attempts }}</td>
            <td>{{ $j->error }}</td>
            <td style="color:var(--muted)">{{ optional($j->updated_at)->format('Y-m-d H:i:s') }}</td>
        </tr>
    @empty
        <tr><td colspan="7" style="color:var(--muted)">Queue is empty.</td></tr>
    @endforelse
    </tbody>
</table>

<h2 style="font-size:1.05rem;font-weight:700;margin:.4rem 0 .6rem">Users</h2>
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
    .qstate { font-size:.72rem; font-weight:700; padding:.12rem .5rem; border-radius:.3rem; background:rgba(255,255,255,.06); }
    .q-queued { color:#9aa; } .q-running { color:#fbbf24; } .q-done { color:#6ee7b7; } .q-error { color:#f87171; }
</style>
@endsection
