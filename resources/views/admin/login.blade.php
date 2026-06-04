@extends('admin.layout')
@section('title', 'Admin login')
@section('content')
<div class="card narrow">
    <h1>Admin sign in</h1>
    <form method="POST" action="{{ route('admin.login.store') }}">
        @csrf
        <label>Email
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </label>
        <label>Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.key') }}"></div>
        <button type="submit">Sign in</button>
    </form>
</div>
@endsection
