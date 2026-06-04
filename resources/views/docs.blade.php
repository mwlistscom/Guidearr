<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentation · {{ config('app.name', 'Guidearr') }}</title>
    <link rel="icon" href="{{ route('branding.icon') }}">
    <style>
        :root { color-scheme: dark;
            --accent:#f47521; --bg:#0b0c0e; --panel:#16181d; --panel2:#1c1f26;
            --border:rgba(255,255,255,.08); --text:#e7e7ea; --muted:#9aa0a8; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background:var(--bg); color:var(--text); line-height:1.6; }
        a { color:var(--accent); text-decoration:none; }
        a:hover { text-decoration:underline; }
        .wrap { max-width:46rem; margin:0 auto; padding:2.5rem 1.5rem 4rem; }
        header.top { display:flex; align-items:center; gap:.8rem; margin-bottom:2rem;
            padding-bottom:1.4rem; border-bottom:1px solid var(--border); }
        header.top img { width:40px; height:40px; object-fit:contain; }
        header.top .t { font-weight:800; letter-spacing:-.02em; font-size:1.15rem; }
        header.top .back { margin-left:auto; font-size:.9rem; }
        h1 { font-size:1.9rem; font-weight:800; letter-spacing:-.025em; margin-bottom:.4rem; }
        .lead { color:var(--muted); margin-bottom:2rem; }
        nav.toc { background:var(--panel); border:1px solid var(--border); border-radius:.8rem;
            padding:1rem 1.25rem; margin-bottom:2.4rem; }
        nav.toc strong { font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); }
        nav.toc ul { list-style:none; margin-top:.5rem; display:flex; flex-direction:column; gap:.35rem; }
        section { margin-bottom:2.6rem; scroll-margin-top:1.5rem; }
        section h2 { font-size:1.3rem; font-weight:700; margin-bottom:.8rem; letter-spacing:-.01em;
            display:flex; align-items:center; gap:.5rem; }
        section h2 .num { display:inline-flex; align-items:center; justify-content:center;
            width:1.6rem; height:1.6rem; font-size:.85rem; border-radius:.45rem;
            background:rgba(244,117,33,.14); color:var(--accent); flex-shrink:0; }
        ol, ul.steps { margin:.6rem 0 .6rem 1.4rem; display:flex; flex-direction:column; gap:.45rem; }
        p { margin:.6rem 0; }
        code, .path { background:var(--panel2); border:1px solid var(--border); border-radius:.35rem;
            padding:.08rem .4rem; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.86em; }
        .note { background:var(--panel); border:1px solid var(--border); border-left:3px solid var(--accent);
            border-radius:.5rem; padding:.8rem 1rem; margin:.9rem 0; font-size:.92rem; color:#cfd2d8; }
        .danger { border-left-color:#f87171; }
        footer { margin-top:3rem; padding-top:1.4rem; border-top:1px solid var(--border);
            color:var(--muted); font-size:.88rem; }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <img src="{{ route('branding.icon') }}" alt="{{ config('app.name', 'Guidearr') }}">
        <span class="t">{{ config('app.name', 'Guidearr') }}</span>
        <a class="back" href="{{ url('/dashboard') }}">← Back to app</a>
    </header>

    <h1>Documentation</h1>
    <p class="lead">Guides for managing your account. More will be added over time.</p>

    <nav class="toc">
        <strong>On this page</strong>
        <ul>
            <li><a href="#twofa">Setting up two-factor authentication</a></li>
            <li><a href="#password">Changing your password</a></li>
            <li><a href="#email">Verifying your email</a></li>
            <li><a href="#delete">Deleting your account</a></li>
        </ul>
    </nav>

    <section id="twofa">
        <h2><span class="num">1</span> Setting up two-factor authentication (2FA)</h2>
        <p>Two-factor authentication adds a one-time code from your phone on top of your password, so your account stays safe even if your password is exposed.</p>
        <ol>
            <li>Open <span class="path">Settings → Security</span>. You may be asked to re-enter your password first.</li>
            <li>Under <strong>Two-Factor Authentication</strong>, choose <strong>Enable</strong>.</li>
            <li>Scan the QR code with an authenticator app — Google Authenticator, Authy, 1Password, Microsoft Authenticator, or similar. If you can't scan, enter the setup key shown beside the code manually.</li>
            <li>Type the current 6-digit code from the app to confirm and finish enabling.</li>
            <li><strong>Save your recovery codes</strong> somewhere safe (a password manager is ideal). They're the only way back in if you lose your phone.</li>
        </ol>
        <div class="note">To turn 2FA off, return to <span class="path">Settings → Security</span> and choose <strong>Disable</strong>. If you've lost your device, use one of your saved recovery codes at the 2FA prompt when logging in.</div>
    </section>

    <section id="password">
        <h2><span class="num">2</span> Changing your password</h2>
        <ol>
            <li>Open <span class="path">Settings → Security</span>.</li>
            <li>Under <strong>Update password</strong>, enter your current password, then your new password twice.</li>
            <li>Choose a long, unique password — a passphrase or a password-manager-generated value is best.</li>
            <li>Save. You'll stay logged in on this device.</li>
        </ol>
    </section>

    <section id="email">
        <h2><span class="num">3</span> Verifying your email</h2>
        <p>When you register, we email you a 6-digit verification code.</p>
        <ol>
            <li>Check your inbox for the message from {{ config('app.name', 'Guidearr') }} and copy the 6-digit code.</li>
            <li>Enter it on the verification screen to confirm your address.</li>
            <li>Codes expire after 15 minutes — if yours has, request a new one from the same screen.</li>
        </ol>
        <div class="note">Not seeing the email? Check your spam folder, and make sure the address on your account is correct under <span class="path">Settings → Profile</span>.</div>
    </section>

    <section id="delete">
        <h2><span class="num">4</span> Deleting your account</h2>
        <ol>
            <li>Open <span class="path">Settings → Profile</span>.</li>
            <li>Scroll to the <strong>Delete account</strong> section at the bottom.</li>
            <li>Confirm with your password when prompted.</li>
        </ol>
        <div class="note danger"><strong>This is permanent.</strong> Deleting your account removes your profile and associated data and can't be undone. Export or back up anything you want to keep first.</div>
    </section>

    <footer>
        More guides are on the way. If something here is unclear or you're stuck, contact your administrator.
    </footer>
</div>
</body>
</html>
