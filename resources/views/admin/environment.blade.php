@extends('admin.layout')
@section('title', 'Environment')
@section('content')
<style>
    .env-row { display:grid; grid-template-columns:minmax(11rem,15rem) 1fr; gap:1rem; align-items:center;
        padding:.55rem .5rem; border-bottom:1px solid var(--border); border-radius:.4rem; cursor:default; }
    .env-row:last-child { border-bottom:0; }
    .env-row:hover { background:rgba(255,255,255,.035); }
    .env-key { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.85rem; color:#c4c4cc;
        display:flex; align-items:center; gap:.5rem; word-break:break-all; }
    .env-row input { margin:0; }
    .env-val { display:flex; align-items:center; gap:.45rem; }
    .env-val input { flex:1; }
    .tag { font-size:.62rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
        padding:.1rem .4rem; border-radius:999px; flex-shrink:0; }
    .tag.secret { background:rgba(251,191,36,.14); color:#fcd34d; }
    .tag.locked { background:rgba(248,113,113,.14); color:#fca5a5; }
    .eye { background:transparent; border:1px solid rgba(255,255,255,.14); color:var(--muted);
        padding:.45rem; border-radius:.5rem; display:inline-flex; align-items:center; justify-content:center;
        flex-shrink:0; cursor:pointer; }
    .eye:hover { color:#fff; border-color:rgba(255,255,255,.3); background:rgba(255,255,255,.05); }
    .eye svg { width:17px; height:17px; stroke-linecap:round; stroke-linejoin:round; }
    .env-save { margin-top:1.5rem; display:flex; align-items:center; gap:1rem; }
    .env-save .note { color:var(--muted); font-size:.82rem; }
    input:disabled { opacity:.6; cursor:not-allowed; }
</style>

<h1>Environment</h1>
<p class="muted">Edit values from the application's <code>.env</code> file. A timestamped backup is written before every save and the config cache is cleared automatically.@if ($lastBackup) Last backup: <code>{{ $lastBackup }}</code>.@endif</p>

<form method="POST" action="{{ route('admin.environment.update') }}" autocomplete="off">
    @csrf
    @method('PUT')
    <div class="card">
        @forelse ($entries as $e)
            @if ($e['type'] === 'pair')
                <div class="env-row" title="{{ $e['key'] }} — {{ $e['desc'] }}@if ($e['locked']) (locked, read-only)@elseif ($e['secret']) (secret)@endif">
                    <div class="env-key">
                        {{ $e['key'] }}
                        @if ($e['locked'])<span class="tag locked">locked</span>
                        @elseif ($e['secret'])<span class="tag secret">secret</span>@endif
                    </div>
                    @if ($e['locked'])
                        <div class="env-val"><input type="text" value="{{ $e['value'] }}" disabled></div>
                    @elseif ($e['secret'])
                        <div class="env-val">
                            <input type="password" name="env[{{ $e['key'] }}]" value="{{ $e['value'] }}" autocomplete="off">
                            <button type="button" class="eye" data-eye aria-label="Show value">
                                <svg class="eye-on"  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    @else
                        <div class="env-val"><input type="text" name="env[{{ $e['key'] }}]" value="{{ $e['value'] }}"></div>
                    @endif
                </div>
            @endif
        @empty
            <p class="empty">Could not read any variables from .env.</p>
        @endforelse
    </div>

    <div class="env-save">
        <button type="submit">Save changes</button>
        <span class="note">Writes <code>.env</code> atomically · backs up first · clears config cache</span>
    </div>
</form>

<script>
    document.querySelectorAll('[data-eye]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = btn.parentElement.querySelector('input');
            var reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            btn.querySelector('.eye-on').style.display  = reveal ? 'none' : '';
            btn.querySelector('.eye-off').style.display = reveal ? '' : 'none';
            btn.setAttribute('aria-label', reveal ? 'Hide value' : 'Show value');
        });
    });
</script>
@endsection
