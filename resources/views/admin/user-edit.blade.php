@extends('admin.layout')
@section('title', 'Edit user')
@section('content')
<h1>Edit user</h1>
<div class="card" style="max-width:30rem">
    <form method="POST" action="{{ route('admin.users.update', $user) }}">
        @csrf
        @method('PUT')

        <label>Name
            <input name="name" value="{{ old('name', $user->name) }}" required>
        </label>

        <label>Email
            <input type="email" name="email" value="{{ old('email', $user->email) }}" required>
        </label>

        @php($role = old('role', $user->is_admin ? 'admin' : 'user'))
        <label>Role
            <select name="role">
                <option value="user"  {{ $role === 'user'  ? 'selected' : '' }}>User</option>
                <option value="admin" {{ $role === 'admin' ? 'selected' : '' }}>Admin</option>
            </select>
        </label>

        @php($st = old('status', $user->status === 'active' ? 'active' : 'banned'))
        <label>Status
            <select name="status">
                <option value="active"   {{ $st === 'active'   ? 'selected' : '' }}>Enabled</option>
                <option value="banned" {{ $st === 'banned' ? 'selected' : '' }}>Banned</option>
            </select>
        </label>

        @php($vf = old('verified', $user->email_verified_at ? 'verified' : 'unverified'))
        <label>Email verification
            <select name="verified">
                <option value="verified"   {{ $vf === 'verified'   ? 'selected' : '' }}>Verified</option>
                <option value="unverified" {{ $vf === 'unverified' ? 'selected' : '' }}>Not verified</option>
            </select>
        </label>

        <label>New password <span class="muted" style="font-size:.78rem">(leave blank to keep current)</span>
            <input type="password" name="password" autocomplete="new-password">
        </label>
        <label>Confirm new password
            <input type="password" name="password_confirmation" autocomplete="new-password">
        </label>

        <div style="display:flex; gap:.6rem; margin-top:1.3rem">
            <button type="submit">Save changes</button>
            <a class="ghostbtn" href="{{ route('admin.users') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
