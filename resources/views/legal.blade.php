<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name', 'Guidearr') }}</title>
    <link rel="icon" href="{{ route('branding.icon') }}">
    <style>
        :root { --accent:#f47521; }
        * { box-sizing:border-box; }
        body { margin:0; background:#0e0f13; color:#e6e7ea; font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; line-height:1.65; }
        .wrap { max-width:46rem; margin:0 auto; padding:2.5rem 1.3rem 4rem; }
        .brand { display:flex; align-items:center; gap:.6rem; margin-bottom:1.4rem; }
        .brand img { height:30px; }
        .brand span { font-weight:700; font-size:1.05rem; }
        .doc h1 { font-size:1.55rem; margin:0 0 .4rem; }
        .doc h2 { font-size:1.12rem; margin:1.9rem 0 .5rem; color:#fff; }
        .doc h3 { font-size:1rem; margin:1.3rem 0 .4rem; color:#fff; }
        .doc p, .doc li { color:#cdd2da; font-size:.95rem; }
        .doc a { color:var(--accent); }
        .doc ul { padding-left:1.2rem; }
        .doc li { margin:.25rem 0; }
        .doc code { background:#16171a; border:1px solid rgba(255,255,255,.10); border-radius:.3rem; padding:.05rem .35rem; font-size:.85em; }
        .doc em { color:#9aa0aa; }
        .doc table { border-collapse:collapse; width:100%; margin:.6rem 0; display:block; overflow-x:auto; }
        .doc th, .doc td { border:1px solid rgba(255,255,255,.12); padding:.45rem .6rem; text-align:left; font-size:.88rem; }
        .doc th { background:#16171a; color:#fff; }
        .updated { color:#7a7f89; font-size:.82rem; margin:.2rem 0 1.4rem; }
        .nav { margin-top:2.2rem; padding-top:1.2rem; border-top:1px solid rgba(255,255,255,.10); font-size:.9rem; }
        .nav a { color:var(--accent); text-decoration:none; margin-right:1.1rem; }
        .nav a:hover { text-decoration:underline; }
        a.back { color:var(--accent); text-decoration:none; }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="brand" href="{{ url('/') }}" style="text-decoration:none;color:inherit">
            <img src="{{ route('branding.icon') }}" alt="">
            <span>{{ config('app.name', 'Guidearr') }}</span>
        </a>
        <div class="doc">
            {!! $html !!}
        </div>
        @if ($updatedAt)
            <p class="updated">Last updated: {{ \Illuminate\Support\Carbon::parse($updatedAt)->format('j M Y') }}</p>
        @endif
        <div class="nav">
            <a href="{{ route('legal.privacy') }}">Privacy</a>
            <a href="{{ route('legal.terms') }}">Terms</a>
            <a href="{{ route('legal.cookies') }}">Cookies</a>
            <a href="{{ route('legal.data-deletion') }}">Data deletion</a>
            <a class="back" href="{{ $back }}">&larr; Back</a>
        </div>
    </div>
</body>
</html>
