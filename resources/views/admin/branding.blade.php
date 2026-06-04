@extends('admin.layout')
@section('title', 'Branding')
@section('content')
<style>
    .brand-preview { display:flex; align-items:center; gap:1.1rem; margin-bottom:1.4rem; }
    .brand-preview img { object-fit:contain; background:#0e0f13;
        border:1px solid var(--border); border-radius:.7rem; padding:.4rem; }
    .brand-preview img.icon { width:76px; height:76px; }
    .brand-preview img.logo { width:200px; height:76px; }
    input[type=file] { width:100%; padding:.5rem; border-radius:.55rem; color:var(--text);
        background:#0e0f13; border:1px solid rgba(255,255,255,.14); font-size:.9rem; }
    input[type=file]::file-selector-button { margin-right:.8rem; padding:.4rem .8rem; border-radius:.4rem;
        border:1px solid rgba(255,255,255,.16); background:var(--panel2); color:var(--text); cursor:pointer; }
</style>

<h1>Branding</h1>
<p class="muted">Two images: the <strong>app icon</strong> (a small square mark used in the sidebar, header, and browser tab) and the <strong>logo</strong> (a wide wordmark shown on the landing page). PNG, JPG, WEBP or GIF, up to 10&nbsp;MB.</p>

{{-- ── App icon ──────────────────────────────────────────────── --}}
<h2 style="margin-top:1.6rem">App icon <span class="muted" style="font-weight:400">— small square mark</span></h2>
<div class="card" style="max-width:34rem">
    <div class="brand-preview">
        <img class="icon" src="{{ route('branding.icon') }}?t={{ time() }}" alt="Current app icon">
        <div class="muted">{{ $hasCustomIcon ? 'Currently using a custom icon.' : 'Currently using the default icon.' }} A square image works best.</div>
    </div>

    <form method="POST" action="{{ route('admin.branding.update', 'icon') }}" enctype="multipart/form-data">
        @csrf
        <label>Upload a new app icon</label>
        <input type="file" name="icon" accept="image/png,image/jpeg,image/webp,image/gif" required>
        <div style="margin-top:1.3rem">
            <button type="submit">Upload icon</button>
        </div>
    </form>

    @if ($hasCustomIcon)
        <form method="POST" action="{{ route('admin.branding.reset', 'icon') }}"
              onsubmit="return confirm('Reset to the default app icon?')" style="margin-top:.9rem">
            @csrf @method('DELETE')
            <button type="submit" class="ghost">Reset icon to default</button>
        </form>
    @endif
</div>

{{-- ── Logo ──────────────────────────────────────────────────── --}}
<h2 style="margin-top:2.2rem">Logo <span class="muted" style="font-weight:400">— wide wordmark (landing page)</span></h2>
<div class="card" style="max-width:34rem">
    <div class="brand-preview">
        <img class="logo" src="{{ route('branding.logo') }}?t={{ time() }}" alt="Current logo">
        <div class="muted">{{ $hasCustomLogo ? 'Currently using a custom logo.' : 'Currently using the default logo.' }} A wide image works best.</div>
    </div>

    <form method="POST" action="{{ route('admin.branding.update', 'logo') }}" enctype="multipart/form-data">
        @csrf
        <label>Upload a new logo</label>
        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" required>
        <div style="margin-top:1.3rem">
            <button type="submit">Upload logo</button>
        </div>
    </form>

    @if ($hasCustomLogo)
        <form method="POST" action="{{ route('admin.branding.reset', 'logo') }}"
              onsubmit="return confirm('Reset to the default logo?')" style="margin-top:.9rem">
            @csrf @method('DELETE')
            <button type="submit" class="ghost">Reset logo to default</button>
        </form>
    @endif
</div>

{{-- ── Footer copyright ──────────────────────────────────────── --}}
<h1 style="margin-top:2.5rem">Footer copyright</h1>
<p class="muted">Shown at the bottom of the landing page as <code>&copy; {{ date('Y') }} &lt;your text&gt;</code>. The year updates automatically.</p>

<div class="card" style="max-width:34rem">
    <form method="POST" action="{{ route('admin.branding.copyright') }}">
        @csrf @method('PUT')
        <label>Copyright holder</label>
        <input type="text" name="copyright" value="{{ old('copyright', $copyright) }}" maxlength="255" required>
        <div style="margin-top:1.3rem">
            <button type="submit">Save copyright</button>
        </div>
    </form>
</div>
@endsection
