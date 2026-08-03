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
    .muted.small { font-size:.82rem; line-height:1.55; }
    .assetinfo { display:block; margin-top:.25rem; font-family:ui-monospace,monospace; font-size:.78rem; }
    .assetinfo.warn { color:#fbbf24; }
    .warnbox { margin:.7rem 0 0; padding:.55rem .75rem; border-radius:.45rem; font-size:.82rem; line-height:1.5;
        background:rgba(251,191,36,.10); border:1px solid rgba(251,191,36,.35); color:#fbbf24; }
</style>

<h1>Branding</h1>
<p class="muted">Two images. The <strong>app icon</strong> is a small square mark; the <strong>logo</strong> is a wide wordmark for the landing page. PNG, JPG, WEBP or GIF &mdash; PNG with a transparent background looks best on both the dark app chrome and the light landing page.</p>
<p class="muted small">Uploads are capped at 10&nbsp;MB, but <strong>size them to the guidance below</strong>: neither image is resized on the server, so an oversized file is downloaded in full by every visitor and then scaled down and discarded by their browser. It costs load time and bandwidth and looks no sharper.</p>

@php
    $fmt = function (array $a) {
        $dim = $a['width'] && $a['height'] ? "{$a['width']} × {$a['height']}" : 'unknown size';
        $kb  = $a['bytes'] >= 1048576
            ? number_format($a['bytes'] / 1048576, 2) . ' MB'
            : number_format($a['bytes'] / 1024) . ' KB';
        return "{$dim}, {$kb}";
    };
@endphp

{{-- ── App icon ──────────────────────────────────────────────── --}}
<h2 style="margin-top:1.6rem">App icon <span class="muted" style="font-weight:400">— small square mark</span></h2>
<div class="card" style="max-width:34rem">
    <div class="brand-preview">
        <img class="icon" src="{{ route('branding.icon') }}?t={{ time() }}" alt="Current app icon">
        <div class="muted">
            {{ $hasCustomIcon ? 'Currently using a custom icon.' : 'Currently using the default icon.' }}
            <span class="assetinfo {{ $assets['icon']['oversized'] ? 'warn' : '' }}">{{ $fmt($assets['icon']) }}</span>
        </div>
    </div>

    <p class="muted small">
        <strong>Recommended: {{ $assets['icon']['recommended'] }}, square, under 150&nbsp;KB.</strong>
        It appears in the sidebar and header (32&nbsp;px), on the sign-in, sign-up and password-reset
        screens (36&nbsp;px), and as the browser-tab icon on the public pages. The largest it is ever
        drawn is 76&nbsp;px — the preview above — so 256&nbsp;× 256 already covers high-DPI screens.
        Non-square images are letterboxed, not cropped.
    </p>

    @if ($assets['icon']['oversized'])
        <p class="warnbox">This icon is larger than it needs to be. Re-exporting it at
            {{ $assets['icon']['recommended'] }} will look identical and load faster.</p>
    @endif

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
        <div class="muted">
            {{ $hasCustomLogo ? 'Currently using a custom logo.' : 'Currently using the default logo.' }}
            <span class="assetinfo {{ $assets['logo']['oversized'] ? 'warn' : '' }}">{{ $fmt($assets['logo']) }}</span>
        </div>
    </div>

    <p class="muted small">
        <strong>Recommended: {{ $assets['logo']['recommended'] }} or similar, wide, under 250&nbsp;KB.</strong>
        It appears once, in the hero of the public landing page, at a maximum of 300&nbsp;px wide —
        so 600&nbsp;px covers high-DPI screens. Height is free: any aspect ratio works, as the width
        is what's constrained. Roughly 2:1 matches the shipped default.
    </p>

    @if ($assets['logo']['oversized'])
        <p class="warnbox">This logo is larger than it needs to be. Re-exporting it at
            {{ $assets['logo']['recommended'] }} will look identical and load faster.</p>
    @endif

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

{{-- ── Copyright & licence (read-only) ──────────────────────── --}}
<h1 style="margin-top:2.5rem">Copyright &amp; licence</h1>
<p class="muted">This project is owned by its author and licensed for non-commercial use. These terms are fixed.</p>

<div class="card" style="max-width:40rem">
    <p style="margin:0 0 .5rem"><strong>&copy; {{ date('Y') }} {{ $copyright }}.</strong> All rights reserved.</p>
    <p class="muted" style="margin:0">{{ $license }}</p>
    <p class="muted" style="margin:.7rem 0 0;font-size:.8rem">Full terms: <code>LICENSE</code> in the project root.</p>
</div>
@endsection
