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
    <p class="lead">How to build and manage IPTV playlists in {{ config('app.name', 'Guidearr') }} — plus account basics.</p>

    <nav class="toc">
        <strong>On this page</strong>
        <ul>
            <li><a href="#overview">How it fits together</a></li>
            <li><a href="#providers">1 · Adding a provider (your source)</a></li>
            <li><a href="#create">2 · Creating a playlist</a></li>
            <li><a href="#channels">3 · Editing channels</a></li>
            <li><a href="#groups">4 · Managing groups</a></li>
            <li><a href="#links">5 · Playlist links (M3U / EPG / Stream)</a></li>
            <li><a href="#guide">6 · The TV guide (EPG)</a></li>
            <li><a href="#twofa">7 · Two-factor authentication</a></li>
            <li><a href="#password">8 · Changing your password</a></li>
            <li><a href="#email">9 · Verifying your email</a></li>
            <li><a href="#delete">10 · Deleting your account</a></li>
        </ul>
    </nav>

    <section id="overview">
        <h2>How it fits together</h2>
        <p>Three things work together:</p>
        <ul class="steps">
            <li><strong>Providers</strong> are your sources — an Xtream account, an M3U URL, or an XMLTV guide feed. {{ config('app.name', 'Guidearr') }} ingests their channels and guide data and keeps a local copy.</li>
            <li><strong>Playlists</strong> are the lists you build <em>from</em> one or more providers: pick channels, arrange them into groups, rename and reorder, and hand out clean links.</li>
            <li><strong>Links</strong> are the URLs you paste into your IPTV player — one for the channel list (M3U), one for the guide (EPG/XMLTV), and one stream list (for <code>.strm</code> files).</li>
        </ul>
        <p>The dashboard shows your <strong>Providers</strong> on the left and your <strong>Playlists</strong> on the right. Selecting a row opens its detail panel below.</p>
    </section>

    <section id="providers">
        <h2><span class="num">1</span> Adding a provider (your source)</h2>
        <ol>
            <li>In the <strong>Provider</strong> panel, click the <strong>+</strong> button.</li>
            <li>Pick a <strong>type</strong>: <strong>Xtream</strong> (username/password + server URL), <strong>M3U</strong> (a playlist URL, with an optional separate <strong>EPG URL</strong> for its guide), or <strong>XMLTV</strong> (a guide-only feed — no channels).</li>
            <li>Give it a name, fill in the URL (and credentials for Xtream), and save.</li>
            <li>Use the <strong>refresh</strong> button to ingest it. An update log opens — when it reads <strong>Done</strong>, the channels (and guide) are loaded.</li>
            <li>Click a provider row to browse what came in: its channels and groups. For an <strong>XMLTV</strong> provider, you'll see the <strong>guide</strong> instead — channels on the left, that channel's programmes on the right.</li>
        </ol>
        <div class="note">Refreshes also run automatically on the schedule you set. If a source fails repeatedly it's disabled so it doesn't keep retrying — re-enable it after fixing the URL or credentials.</div>
    </section>

    <section id="create">
        <h2><span class="num">2</span> Creating a playlist</h2>
        <ol>
            <li>In the <strong>Playlist</strong> panel, click <strong>+</strong> (create).</li>
            <li>Enter a <strong>name</strong>.</li>
            <li><strong>First channel #</strong> — the number the first channel gets in the M3U (e.g. 100); each following channel increments from there.</li>
            <li><strong>Emit #EXTGRP tags</strong> — leave on if your player groups channels using <code>#EXTGRP</code> lines.</li>
            <li><strong>IP lock</strong> (optional) — lock the playlist to a single IP address. Leave blank for normal use (a rolling device limit still applies).</li>
            <li>Select one or more <strong>providers</strong> to seed from, and optionally a <strong>guide provider</strong> for the EPG.</li>
            <li>Create. The playlist is filled with every channel and group from the providers you picked — ready to trim and arrange.</li>
        </ol>
    </section>

    <section id="channels">
        <h2><span class="num">3</span> Editing channels</h2>
        <p>Click a playlist row to open its editor — <strong>channels on the left, groups on the right</strong>.</p>
        <ul class="steps">
            <li><strong>Reorder:</strong> press and drag a channel row up or down. Order here is the order players show.</li>
            <li><strong>Move to a row #:</strong> use the move button on a row to jump it to a specific position without dragging across pages.</li>
            <li><strong>Edit inline:</strong> double-click a cell to change the TVG-ID, TVG name, title, or M3U URL; the <strong>Group</strong> cell is a dropdown of the playlist's groups. Your edits override the provider's values for that channel.</li>
            <li><strong>Enable / disable:</strong> the <strong>On</strong> toggle keeps a channel in the list but excludes it from the output when off.</li>
            <li><strong>Delete / restore:</strong> deleting hides a channel from the output. Turn on <strong>Show deleted</strong> to see dimmed rows and restore them.</li>
            <li><strong>TV guide:</strong> the <strong>G</strong> button on a row shows that channel's upcoming programmes (using the playlist's guide provider).</li>
            <li><strong>Add a channel manually:</strong> use the add control to insert a custom channel (name, URL, group) that isn't from a provider.</li>
            <li><strong>Page size:</strong> change how many rows show per page with the selector.</li>
        </ul>
        <div class="note">Edits are saved as you go. Reordering and edits never change your providers — a playlist is its own arrangement on top of them.</div>
    </section>

    <section id="groups">
        <h2><span class="num">4</span> Managing groups</h2>
        <p>Groups are the categories players show (US Entertainment, Sports, …). The right-hand pane manages them.</p>
        <ul class="steps">
            <li><strong>Reorder:</strong> drag a group to change the order its channels appear in the output.</li>
            <li><strong>Rename:</strong> renaming a group updates every channel in it automatically.</li>
            <li><strong>Enable / disable:</strong> the group's <strong>On</strong> toggle cascades to every channel inside it — turning a group off removes all its channels from the output.</li>
            <li><strong>Delete:</strong> deleting a group cascades to its channels. Use <strong>show deleted groups</strong> to restore one (which restores its channels).</li>
            <li><strong>Add a group:</strong> create an empty group with the <strong>+</strong> control, then move channels into it.</li>
            <li><strong>Reindex:</strong> renumbers positions into clean, evenly-spaced steps (10, 20, 30 …) after lots of editing. There's a reindex button on both the channel and group toolbars.</li>
        </ul>
    </section>

    <section id="links">
        <h2><span class="num">5</span> Playlist links (M3U / EPG / Stream)</h2>
        <p>On a playlist row, the <strong>Links</strong> button opens three ready-to-copy URLs:</p>
        <ul class="steps">
            <li><strong>M3U Link</strong> — the channel list. Paste it where your IPTV player asks for an M3U / playlist URL.</li>
            <li><strong>EPG / Guide Link</strong> — the XMLTV guide for this playlist's channels. Paste it where the player asks for an EPG / XMLTV URL.</li>
            <li><strong>Stream Link</strong> — a JSON channel list, used to generate <code>.strm</code> files (Plex / Jellyfin / Emby) with the <a href="https://github.com/mwlistscom/GetSTRM" target="_blank" rel="noopener">GetSTRM</a> tool.</li>
        </ul>
        <p>Each link carries the playlist's <strong>key</strong>. The <strong>key</strong> button on the row regenerates it — handy if a link leaks, but note it <strong>invalidates the old links</strong>, so anyone using them will need the new ones.</p>
        <div class="note">If the Links overlay says the base URL isn't set, an administrator needs to set it under <span class="path">Admin → Config → Playlist links</span>.</div>
    </section>

    <section id="guide">
        <h2><span class="num">6</span> The TV guide (EPG)</h2>
        <ol>
            <li>Add an <strong>XMLTV</strong> provider (or an <strong>M3U</strong> provider with an EPG URL) and refresh it so its guide loads.</li>
            <li>When creating or editing a playlist, set that provider as the playlist's <strong>guide provider</strong>.</li>
            <li>Hand out the <strong>EPG / Guide Link</strong> from the Links overlay — it serves guide data for just the channels in that playlist.</li>
        </ol>
        <p>The guide matches programmes to channels by <strong>tvg-id</strong>, so a channel only shows a guide if its tvg-id matches one in the guide source. You can check any channel's guide with the <strong>G</strong> button in the channel editor.</p>
    </section>

    <section id="twofa">
        <h2><span class="num">7</span> Two-factor authentication (2FA)</h2>
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
        <h2><span class="num">8</span> Changing your password</h2>
        <ol>
            <li>Open <span class="path">Settings → Security</span>.</li>
            <li>Under <strong>Update password</strong>, enter your current password, then your new password twice.</li>
            <li>Choose a long, unique password — a passphrase or a password-manager-generated value is best.</li>
            <li>Save. You'll stay logged in on this device.</li>
        </ol>
    </section>

    <section id="email">
        <h2><span class="num">9</span> Verifying your email</h2>
        <p>When you register, we email you a 6-digit verification code.</p>
        <ol>
            <li>Check your inbox for the message from {{ config('app.name', 'Guidearr') }} and copy the 6-digit code.</li>
            <li>Enter it on the verification screen to confirm your address.</li>
            <li>Codes expire after 15 minutes — if yours has, request a new one from the same screen.</li>
        </ol>
        <div class="note">Not seeing the email? Check your spam folder, and make sure the address on your account is correct under <span class="path">Settings → Profile</span>.</div>
    </section>

    <section id="delete">
        <h2><span class="num">10</span> Deleting your account</h2>
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
