@extends('admin.layout')
@section('title', 'Dashboard')
@section('content')
<h1>Dashboard</h1>
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
@endsection
