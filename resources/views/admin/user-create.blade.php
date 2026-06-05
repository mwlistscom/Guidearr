@extends('admin.layout')
@section('title', 'Add user')
@section('content')
<h1>Add user</h1>
<p class="muted" style="margin-bottom:1rem;max-width:30rem">Creates an account that's <strong>active and email-verified immediately</strong> — no verification email is sent, so this works without a configured mail server. Ideal for personal or self-hosted setups.</p>

<div class="card" style="max-width:30rem">
    <form method="POST" action="{{ route('admin.users.store') }}">
        @csrf

        <label>Name
            <input name="name" value="{{ old('name') }}" required autofocus>
        </label>

        <label>Email
            <input type="email" name="email" value="{{ old('email') }}" required>
        </label>

        @php($role = old('role', 'user'))
        <label>Role
            <select name="role">
                <option value="user"  {{ $role === 'user'  ? 'selected' : '' }}>User</option>
                <option value="admin" {{ $role === 'admin' ? 'selected' : '' }}>Admin</option>
            </select>
        </label>

        <label>Password
            <input type="password" name="password" autocomplete="new-password" required>
        </label>
        <label>Confirm password
            <input type="password" name="password_confirmation" autocomplete="new-password" required>
        </label>

        <div style="display:flex; gap:.6rem; margin-top:1.3rem">
            <button type="submit">Create user</button>
            <a class="ghostbtn" href="{{ route('admin.users') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
