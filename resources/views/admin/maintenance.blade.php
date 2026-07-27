@extends('admin.layout')
@section('title', 'Maintenance')
@section('content')
<meta name="csrf-token" content="{{ csrf_token() }}">
@php
    $human = function ($b) {
        $b = (float) $b;
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
        return ($i === 0 ? (int) $b : number_format($b, 1)) . ' ' . $u[$i];
    };
    $ago = function ($dt) {
        return $dt ? $dt->diffForHumans() : 'never served';
    };
@endphp
<h1>Maintenance</h1>

@if (session('status'))
    <div class="flash">{{ session('status') }}</div>
@endif

<p class="muted">A playlist is counted as <strong>stale</strong> when it hasn't been served (M3U, EPG, or STRM) within the chosen window — or has never been served. Deleting a playlist also removes its SQLite store on disk. Providers are shown for reference only.</p>

<form method="GET" action="{{ route('admin.maintenance') }}" class="daysbar">
    <label>Not served in the last
        <input type="number" name="days" min="0" max="3650" value="{{ $days }}"> days
    </label>
    <button type="submit">Show</button>
    <span class="muted">{{ $totalStale }} stale · {{ $human($reclaimBytes) }} reclaimable</span>
</form>

<h2>Stale playlists</h2>
@if ($stale->isEmpty())
    <p class="muted">No playlists match — nothing to prune in this window.</p>
@else
<form method="POST" action="{{ route('admin.maintenance.prune') }}"
      onsubmit="return confirm('Permanently delete the selected playlist(s) and their stores? This cannot be undone.')">
    @csrf
    <table class="tbl">
        <thead>
            <tr>
                <th class="ck"><input type="checkbox" id="all" checked></th>
                <th>Playlist</th><th>Owner</th><th>Last served</th><th class="r">Store size</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($stale as $p)
                <tr>
                    <td class="ck"><input type="checkbox" name="ids[]" value="{{ $p['id'] }}" class="row" checked></td>
                    <td>{{ $p['name'] }}</td>
                    <td class="muted">{{ $p['user'] }}</td>
                    <td class="muted">{{ $ago($p['last']) }}@if($p['last']) <span class="dim">({{ $p['last']->format('Y-m-d') }})</span>@endif</td>
                    <td class="r">{{ $human($p['bytes']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <button type="submit" class="danger">Delete selected</button>
</form>
@endif

<h2 style="margin-top:2.2rem">Maintenance tasks</h2>
<p class="muted">Run a maintenance job now — it runs in the background and progress streams into the popup.
   Every run (manual or scheduled) is recorded in <code>maintenance.log</code> under
   <a href="{{ route('admin.logs') }}">Logs</a> (kept {{ \App\Support\MaintenanceLog::RETENTION_DAYS }} days).</p>
<div class="taskgrid">
    @foreach ($tasks as $key => $t)
        <div class="taskcard">
            <div class="taskcard-h">
                <span class="taskcard-title">{{ $t['label'] }}</span>
                <span class="sched">{{ $t['schedule'] }}</span>
            </div>
            <p class="taskcard-desc">{{ $t['desc'] }}</p>
            <button type="button" class="run" data-task="{{ $key }}" data-label="{{ $t['label'] }}">Run now</button>
        </div>
    @endforeach
</div>
<h2 style="margin-top:2.2rem">Destructive tasks</h2>
<p class="muted">These <strong>delete accounts</strong> or <strong>edit playlists</strong>. Each runs a
   <strong>dry run first</strong> — you see exactly what would change, then click <strong>Apply for real</strong>
   to commit. They also run automatically on the schedule shown.</p>
<div class="taskgrid">
    @foreach ($destructive as $key => $t)
        <div class="taskcard destructive">
            <div class="taskcard-h">
                <span class="taskcard-title">{{ $t['label'] }}</span>
                <span class="sched">{{ $t['schedule'] }}</span>
            </div>
            <p class="taskcard-desc">{{ $t['desc'] }}</p>
            <button type="button" class="run dry" data-task="{{ $key }}" data-label="{{ $t['label'] }}">Dry run</button>
        </div>
    @endforeach
</div>


{{-- Maintenance-task run popup: streams the background task's log slice. --}}
<div id="mt-modal" class="mt-backdrop" hidden>
    <div class="mt-dialog" role="dialog" aria-modal="true" aria-labelledby="mt-title">
        <div class="mt-head">
            <h3 id="mt-title">Running…</h3>
            <span id="mt-spin" class="mt-spin" aria-hidden="true"></span>
            <button type="button" class="mt-x" id="mt-close" aria-label="Close">&times;</button>
        </div>
        <pre id="mt-out" class="mt-out">Starting…</pre>
        <div class="mt-foot">
            <span id="mt-status" class="muted"></span>
            <button type="button" id="mt-apply" class="danger" hidden>Apply for real</button>
        </div>
    </div>
</div>

<style>
    .muted { color:var(--muted); }
    .muted .dim { opacity:.6; }
    /* Maintenance run popup */
    .mt-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.6); display:flex; align-items:center;
        justify-content:center; z-index:60; }
    .mt-backdrop[hidden] { display:none; }
    .mt-dialog { background:#16171a; border:1px solid rgba(255,255,255,.12); border-radius:.7rem;
        width:min(48rem,94vw); box-shadow:0 20px 60px rgba(0,0,0,.5); display:flex; flex-direction:column; max-height:88vh; }
    .mt-head { display:flex; align-items:center; gap:.7rem; padding:.9rem 1.1rem; border-bottom:1px solid rgba(255,255,255,.08); }
    .mt-head h3 { font-size:1.05rem; font-weight:700; }
    .mt-x { margin-left:auto; background:transparent; border:none; color:var(--muted); font-size:1.4rem; cursor:pointer; line-height:1; }
    .mt-x:hover:not(:disabled) { color:#fff; }
    .mt-x:disabled { opacity:.35; cursor:not-allowed; }
    .mt-spin { width:1rem; height:1rem; border:2px solid rgba(255,255,255,.25); border-top-color:var(--accent);
        border-radius:50%; animation:mt-rot .7s linear infinite; }
    @keyframes mt-rot { to { transform:rotate(360deg); } }
    .mt-out { margin:0; padding:1rem 1.1rem; overflow:auto; background:#0c0d10; font-family:ui-monospace,Menlo,monospace;
        font-size:.8rem; line-height:1.5; color:#cdd2da; white-space:pre-wrap; word-break:break-word; flex:1; min-height:8rem; }
    .mt-foot { padding:.7rem 1.1rem; border-top:1px solid rgba(255,255,255,.08); font-size:.85rem;
        display:flex; align-items:center; justify-content:space-between; gap:.8rem; }
    #mt-apply { border-radius:.45rem; padding:.4rem .9rem; font-size:.85rem; font-weight:700; white-space:nowrap; }
    .flash { background:rgba(34,197,94,.15); border:1px solid rgba(34,197,94,.4); color:#bbf7d0;
        padding:.6rem .9rem; border-radius:.5rem; margin-bottom:1rem; }
    .daysbar { display:flex; gap:.8rem; align-items:center; flex-wrap:wrap; margin:1rem 0 .4rem; }
    .daysbar input { width:5rem; background:#0e0f13; border:1px solid rgba(255,255,255,.18);
        color:#e6e7ea; border-radius:.4rem; padding:.3rem .5rem; }
    .tbl { width:100%; border-collapse:collapse; margin:.6rem 0 1rem; font-size:.85rem; }
    .tbl th, .tbl td { text-align:left; padding:.5rem .6rem; border-bottom:1px solid var(--border); }
    .tbl th { color:var(--muted); font-weight:600; }
    .tbl .r { text-align:right; }
    .tbl .ck { width:2.2rem; text-align:center; }
    button.danger { background:#dc2626; color:#fff; border:none; border-radius:.45rem; padding:.5rem .9rem; cursor:pointer; }
    button.danger:hover { filter:brightness(1.1); }
    /* Maintenance task cards. */
    .taskgrid { display:grid; grid-template-columns:repeat(auto-fill,minmax(15rem,1fr)); gap:.8rem; margin:.6rem 0 1rem; }
    .taskcard { background:var(--panel); border:1px solid var(--border); border-radius:.7rem; padding:.9rem 1rem;
        display:flex; flex-direction:column; gap:.4rem; }
    .taskcard-h { display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
    .taskcard-title { font-weight:700; font-size:.92rem; color:#fff; }
    .taskcard-desc { color:var(--muted); font-size:.8rem; flex:1; margin:0; }
    .taskcard .run { align-self:flex-start; background:var(--accent); color:#160a02; border:none; border-radius:.45rem;
        padding:.4rem .8rem; font-size:.82rem; font-weight:700; cursor:pointer; }
    .taskcard .run:hover { filter:brightness(1.08); }
    /* Destructive task cards get a red accent and a dry-run (outline) button. */
    .taskcard.destructive { border-color:rgba(248,113,113,.35); }
    .taskcard .run.dry { background:transparent; color:#fca5a5; border:1px solid rgba(248,113,113,.5); }
    .taskcard .run.dry:hover { background:rgba(248,113,113,.12); filter:none; }
    .sched { font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:var(--muted);
        background:rgba(255,255,255,.06); padding:.12rem .45rem; border-radius:.3rem; white-space:nowrap; }
    .schedlist { list-style:none; margin:.3rem 0 1rem; padding:0; display:grid; gap:.6rem; }
    .schedlist li { font-size:.85rem; }
    .schedlist .sched { margin-left:.4rem; }
</style>

<script>
    document.getElementById('all')?.addEventListener('change', function () {
        document.querySelectorAll('.row').forEach(c => { c.checked = this.checked; });
    });
</script>

<script>
(function () {
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const modal = document.getElementById('mt-modal');
    const out = document.getElementById('mt-out');
    const title = document.getElementById('mt-title');
    const spin = document.getElementById('mt-spin');
    const statusEl = document.getElementById('mt-status');
    const closeBtn = document.getElementById('mt-close');
    const applyBtn = document.getElementById('mt-apply');
    const runUrl = '{{ route('admin.maintenance.run') }}';
    const outUrl = '{{ route('admin.maintenance.output') }}';
    let poll = null, running = false, current = null;

    function open(label, dry) {
        title.textContent = label + (dry ? '  ·  DRY RUN' : '');
        out.textContent = 'Starting…'; statusEl.textContent = ''; applyBtn.hidden = true;
        spin.style.display = ''; running = true; closeBtn.disabled = true; modal.hidden = false;
    }
    function finish(ok, info) {
        running = false; spin.style.display = 'none'; closeBtn.disabled = false;
        statusEl.textContent = info || (ok ? 'Done.' : 'Finished with errors — see the log.');
    }
    function close() { if (running) return; if (poll) { clearInterval(poll); poll = null; } modal.hidden = true; }
    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });

    async function runTask(task, label, opts) {
        opts = opts || {};
        const dry = !!opts.dry, destructive = !!opts.destructive;
        current = { task, label, destructive };
        open(label, dry);
        let res;
        try {
            res = await fetch(runUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ task, dry: dry ? 1 : 0 }),
            });
        } catch (e) { out.textContent = 'Could not start the task.'; finish(false); return; }
        const d = await res.json().catch(() => ({}));
        if (!res.ok) { out.textContent = d.message || 'Could not start the task.'; finish(false); return; }

        const url = outUrl + '?token=' + encodeURIComponent(d.token);
        poll = setInterval(async () => {
            try {
                const p = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
                if (p.started && p.text) { out.textContent = p.text; out.scrollTop = out.scrollHeight; }
                if (p.done) {
                    clearInterval(poll); poll = null;
                    const ok = !/exit=[^0]/.test(p.text);
                    if (dry && destructive && ok) {
                        finish(true, 'Dry run complete — nothing changed. Review above, then Apply.');
                        applyBtn.hidden = false;
                    } else {
                        finish(ok, dry ? 'Dry run complete — nothing changed.' : null);
                    }
                }
            } catch (e) { /* keep polling */ }
        }, 1500);
    }

    applyBtn.addEventListener('click', () => {
        if (!current) return;
        if (!confirm('APPLY “' + current.label + '” for real?\nThis makes the changes shown above and cannot be undone.')) return;
        runTask(current.task, current.label, { dry: false, destructive: true });
    });

    document.querySelectorAll('.taskcard .run').forEach(b => {
        b.addEventListener('click', () => {
            const dry = b.classList.contains('dry');
            if (!dry && !confirm('Run “' + b.dataset.label + '” now?')) return;
            runTask(b.dataset.task, b.dataset.label, { dry: dry, destructive: dry });
        });
    });
})();
</script>
@endsection
