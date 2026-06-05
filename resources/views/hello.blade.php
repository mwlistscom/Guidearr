<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'Guidearr') }}</title>
    <link rel="icon" href="{{ route('branding.icon') }}">
    <style>
        :root { color-scheme: dark; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100svh;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: #e7e7ea;
            background: #08080a;
            background-image:
                radial-gradient(60rem 40rem at 50% -10%, rgba(99,102,241,.18), transparent 60%),
                radial-gradient(40rem 30rem at 100% 110%, rgba(244,117,33,.10), transparent 60%);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 2rem; position: relative; overflow: hidden;
        }
        body::before {
            content: ""; position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(60rem 50rem at 50% 30%, #000, transparent 75%);
            pointer-events: none;
        }
        .wrap { position: relative; text-align: center; max-width: 36rem; }
        .logo {
            width: clamp(180px, 36vw, 300px); height: auto; margin: 0 auto 1.5rem;
            display: block; filter: drop-shadow(0 0 50px rgba(99,102,241,.30));
        }
        p.sub {
            margin: 0 auto 2.25rem; font-size: 1.06rem; line-height: 1.6;
            color: #9a9aa5; max-width: 28rem;
        }
        .actions { display: flex; gap: .85rem; justify-content: center; flex-wrap: wrap; }
        a.btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: .8rem 1.7rem; border-radius: .7rem;
            font-weight: 600; font-size: .98rem; text-decoration: none;
            transition: transform .12s ease, background .2s ease, border-color .2s ease;
        }
        a.btn:hover { transform: translateY(-1px); }
        a.primary { color: #160a02; background: #f47521; }
        a.primary:hover { filter: brightness(1.07); }
        a.ghost { color: #e7e7ea; border: 1px solid rgba(255,255,255,.16); }
        a.ghost:hover { border-color: rgba(255,255,255,.34); background: rgba(255,255,255,.04); }
        footer { position: absolute; bottom: 1.5rem; font-size: .8rem; color: #5b5b66; }
    </style>
</head>
<body>
    <div class="wrap">
        <img class="logo" src="{{ route('branding.logo') }}" alt="{{ config('app.name', 'Guidearr') }}">
        <p class="sub">Your M3U playlists, organized. Build, edit, and serve channel lineups from one private place.</p>
        <div class="actions">
            @auth
                <a class="btn primary" href="{{ route('dashboard') }}">Dashboard</a>
            @else
                <a class="btn primary" href="/login">Log in</a>
                <a class="btn ghost" href="/register">Create account</a>
            @endauth
        </div>
    </div>
    <footer>&copy; {{ date('Y') }} {{ $appCopyright }} &middot; Free for non-commercial use</footer>
</body>
</html>
