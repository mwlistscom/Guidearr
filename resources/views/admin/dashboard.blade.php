@extends('admin.layout')
@section('title', 'Status')
@section('content')
<h1>Status</h1>
<div class="stats">
    <div class="stat"><span class="n">{{ $userCount }}</span><span class="l">Users</span></div>
    <div class="stat"><span class="n">{{ $pending }}</span><span class="l">Pending</span></div>
    <div class="stat"><span class="n">{{ $banned }}</span><span class="l">Banned</span></div>
</div>
<div class="grid">
    <a class="tile" href="{{ route('admin.users') }}"><h3>Users</h3><p>Authorize, ban, or delete accounts.</p></a>
    <a class="tile" href="{{ route('admin.environment') }}"><h3>Environment</h3><p>Edit .env variables safely.</p></a>
    <a class="tile" href="{{ route('admin.branding') }}"><h3>Branding</h3><p>Upload and manage the app icon.</p></a>
</div>

<h2 style="margin-top:2rem">Maintenance</h2>
<div class="card" style="max-width:34rem">
    <p class="muted">Clear all caches and gracefully reload the application workers so changes to environment variables (database, mail, app settings) take effect. This reloads the app only — it does not restart the database, web, or mail containers.</p>
    <form method="POST" action="{{ route('admin.restart') }}"
          onsubmit="return confirm('Reload application services now?')">
        @csrf
        <button type="submit">Reload services</button>
    </form>
</div>
@endsection
