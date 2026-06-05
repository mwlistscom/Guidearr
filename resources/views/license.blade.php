<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>License · {{ config('app.name', 'Guidearr') }}</title>
    <link rel="icon" href="{{ route('branding.icon') }}">
    <style>
        :root { --accent:#f47521; }
        * { box-sizing:border-box; }
        body { margin:0; background:#0e0f13; color:#e6e7ea; font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
        .wrap { max-width:46rem; margin:0 auto; padding:2.5rem 1.3rem 4rem; }
        .brand { display:flex; align-items:center; gap:.6rem; margin-bottom:1.4rem; }
        .brand img { height:30px; }
        .brand span { font-weight:700; font-size:1.05rem; }
        h1 { font-size:1.4rem; margin:0 0 1rem; }
        pre { white-space:pre-wrap; word-wrap:break-word; background:#16171a; border:1px solid rgba(255,255,255,.10);
            border-radius:.6rem; padding:1.2rem 1.3rem; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
            font-size:.84rem; line-height:1.6; color:#cdd2da; }
        a.back { display:inline-block; margin-top:1.3rem; color:var(--accent); text-decoration:none; font-size:.9rem; }
        a.back:hover { text-decoration:underline; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">
            <img src="{{ route('branding.icon') }}" alt="">
            <span>{{ config('app.name', 'Guidearr') }}</span>
        </div>
        <h1>License</h1>
        <pre>{{ $text }}</pre>
        <a class="back" href="{{ $back }}">&larr; Back</a>
    </div>
</body>
</html>
