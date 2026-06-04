@extends('admin.layout')
@section('title', 'Set admin credentials')
@section('content')
<div class="card narrow">
    <h1>Set your credentials</h1>
    <p class="muted">Set a new password before continuing. You can change your username/email here too.</p>
    <form method="POST" action="{{ route('admin.password.update') }}">
        @csrf
        @method('PUT')
        <label>Name
            <input name="name" value="{{ old('name', auth()->user()->name) }}" required>
        </label>
        <label>Email / username
            <input type="email" name="email" value="{{ old('email', auth()->user()->email) }}" required>
        </label>
        <label>New password
            <input type="password" name="password" required autocomplete="new-password">
        </label>
        <label>Confirm password
            <input type="password" name="password_confirmation" required autocomplete="new-password">
        </label>
        <button type="submit">Save &amp; continue</button>
    </form>
</div>
@endsection
