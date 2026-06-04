@extends('admin.layout')
@section('title', 'Branding')
@section('content')
<style>
    .brand-preview { display:flex; align-items:center; gap:1.1rem; margin-bottom:1.4rem; }
    .brand-preview img { width:76px; height:76px; object-fit:contain; background:#0e0f13;
        border:1px solid var(--border); border-radius:.7rem; padding:.3rem; }
    input[type=file] { width:100%; padding:.5rem; border-radius:.55rem; color:var(--text);
        background:#0e0f13; border:1px solid rgba(255,255,255,.14); font-size:.9rem; }
    input[type=file]::file-selector-button { margin-right:.8rem; padding:.4rem .8rem; border-radius:.4rem;
        border:1px solid rgba(255,255,255,.16); background:var(--panel2); color:var(--text); cursor:pointer; }
</style>

<h1>Branding</h1>
<p class="muted">The app icon shown in the sidebar and header. PNG, JPG, WEBP or GIF, up to 2&nbsp;MB. A square image works best.</p>

<div class="card" style="max-width:34rem">
    <div class="brand-preview">
        <img src="{{ route('branding.icon') }}?t={{ time() }}" alt="Current app icon">
        <div class="muted">{{ $hasCustom ? 'Currently using a custom icon.' : 'Currently using the default icon.' }}</div>
    </div>

    <form method="POST" action="{{ route('admin.branding.update') }}" enctype="multipart/form-data">
        @csrf
        <label>Upload a new icon</label>
        <input type="file" name="icon" accept="image/png,image/jpeg,image/webp,image/gif" required>
        <div style="margin-top:1.3rem">
            <button type="submit">Upload</button>
        </div>
    </form>

    @if ($hasCustom)
        <form method="POST" action="{{ route('admin.branding.reset') }}"
              onsubmit="return confirm('Reset to the default icon?')" style="margin-top:.9rem">
            @csrf @method('DELETE')
            <button type="submit" class="ghost">Reset to default</button>
        </form>
    @endif
</div>
@endsection
