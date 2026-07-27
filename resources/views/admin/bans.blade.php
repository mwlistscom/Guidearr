@extends('admin.layout')
@section('title', 'Ban list')
@section('content')
<h1>Ban list</h1>
<p class="muted">Email addresses banned from registering or signing in. A ban is keyed on the email, so it
    stays in force even after the account is deleted. Banning here also disables any matching account;
    removing a ban re-activates a matching banned account.</p>

<form method="POST" action="{{ route('admin.bans.store') }}" class="banadd">
    @csrf
    <input type="email" name="email" placeholder="email@example.com" required autocomplete="off"
           value="{{ old('email') }}">
    <input type="text" name="reason" placeholder="Reason (optional)" value="{{ old('reason') }}">
    <button type="submit">Ban email</button>
</form>

<table id="bans-table">
    <thead>
        <tr>
            <th>Email</th>
            <th>Reason</th>
            <th>Banned by</th>
            <th>When</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($bans as $b)
            <tr>
                <td>{{ $b->email }}</td>
                <td>
                    <form class="inline reason-form" method="POST" action="{{ route('admin.bans.update', $b) }}">
                        @csrf @method('PATCH')
                        <input type="text" name="reason" value="{{ $b->reason }}" placeholder="—"
                               class="reason-input" onchange="this.form.submit()">
                    </form>
                </td>
                <td class="muted">{{ $b->bannedBy?->name ?? $b->bannedBy?->email ?? '—' }}</td>
                <td class="when">{{ optional($b->created_at)->format('Y-m-d H:i') ?? '—' }}</td>
                <td class="actions">
                    <form class="inline" method="POST" action="{{ route('admin.bans.destroy', $b) }}"
                          onsubmit="return confirm('Remove {{ $b->email }} from the ban list? A matching banned account will be re-activated.')">
                        @csrf @method('DELETE')
                        <button class="icon off" title="Remove ban">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">No banned emails.</td></tr>
        @endforelse
    </tbody>
</table>

<style>
    .banadd { display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; margin-bottom:1.4rem; }
    .banadd input[type=email] { flex:1; min-width:14rem; width:auto; }
    .banadd input[type=text] { flex:1; min-width:12rem; width:auto; }
    .banadd button { white-space:nowrap; }
    .reason-input { width:100%; background:transparent; border:1px solid transparent; padding:.3rem .4rem;
        border-radius:.4rem; color:var(--text); font-size:.85rem; }
    .reason-input:hover { border-color:var(--border); }
    .reason-input:focus { border-color:var(--accent); background:#0e0f13; outline:none; }
    td.when { white-space:nowrap; font-variant-numeric:tabular-nums; }
</style>
@endsection
