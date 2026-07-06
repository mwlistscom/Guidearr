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
    .env-val .note { color:var(--muted); font-size:.78rem; }
    .mail-test-btn { flex-shrink:0; background:rgba(96,165,250,.14); color:#93c5fd;
        border:1px solid rgba(96,165,250,.3); padding:.5rem .8rem; border-radius:.5rem; cursor:pointer; font-size:.85rem; }
    .mail-test-btn:hover { background:rgba(96,165,250,.22); color:#bfdbfe; }
    .modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.55); display:none;
        align-items:flex-start; justify-content:center; z-index:60; padding:8vh 1rem 1rem; }
    .modal-backdrop.open { display:flex; }
    .modal { background:var(--panel,#1b1b22); border:1px solid var(--border); border-radius:.7rem;
        width:100%; max-width:30rem; padding:1.3rem 1.4rem; box-shadow:0 20px 60px rgba(0,0,0,.5); }
    .modal h2 { margin:0 0 .3rem; font-size:1.1rem; }
    .modal p.sub { color:var(--muted); font-size:.83rem; margin:0 0 1rem; }
    .modal label { display:block; font-size:.8rem; color:#c4c4cc; margin:0 0 .3rem; }
    .modal input[type=email] { width:100%; margin:0 0 1rem; }
    .modal-actions { display:flex; gap:.6rem; justify-content:flex-end; align-items:center; }
    .modal .ghost { background:transparent; border:1px solid rgba(255,255,255,.16); color:var(--muted); }
    .modal-result { font-size:.83rem; margin:0 0 1rem; padding:.6rem .7rem; border-radius:.5rem; display:none; word-break:break-word; }
    .modal-result.show { display:block; }
    .modal-result.ok  { background:rgba(52,211,153,.13); color:#6ee7b7; border:1px solid rgba(52,211,153,.3); }
    .modal-result.err { background:rgba(248,113,113,.13); color:#fca5a5; border:1px solid rgba(248,113,113,.3); }
    .modal-result.info{ background:rgba(255,255,255,.06); color:var(--muted); border:1px solid var(--border); }
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
                @if ($e['key'] === 'MAIL_PASSWORD')
                    <div class="env-row">
                        <div class="env-key"></div>
                        <div class="env-val">
                            <button type="button" id="mailTestOpen" class="mail-test-btn">✉&nbsp; Send test email…</button>
                            <span class="note">Sends a test message using the mail values above (no need to save first).</span>
                        </div>
                    </div>
                @endif
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

<div class="modal-backdrop" id="mailTestModal" role="dialog" aria-modal="true" aria-labelledby="mailTestTitle">
    <div class="modal">
        <h2 id="mailTestTitle">Send a test email</h2>
        <p class="sub">Delivers a test message using the mail settings currently entered above — handy for confirming SMTP host, port, credentials and the “from” address before saving.</p>
        <div class="modal-result" id="mailTestResult"></div>
        <label for="mailTestTo">Send to</label>
        <input type="email" id="mailTestTo" placeholder="you@example.com" autocomplete="off">
        <div class="modal-actions">
            <button type="button" class="ghost" id="mailTestCancel">Close</button>
            <button type="button" id="mailTestSend">Send test</button>
        </div>
    </div>
</div>

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

    (function () {
        var modal  = document.getElementById('mailTestModal');
        var openBtn = document.getElementById('mailTestOpen');
        if (!modal || !openBtn) { return; }

        var toInput = document.getElementById('mailTestTo');
        var sendBtn = document.getElementById('mailTestSend');
        var cancel  = document.getElementById('mailTestCancel');
        var result  = document.getElementById('mailTestResult');

        function envVal(key) {
            var el = document.querySelector('[name="env[' + key + ']"]');
            return el ? el.value : '';
        }
        function showResult(cls, text) {
            result.className = 'modal-result show ' + cls;
            result.textContent = text;
        }
        function open() {
            result.className = 'modal-result';
            result.textContent = '';
            if (!toInput.value) {
                toInput.value = envVal('MAIL_FROM_ADDRESS') || envVal('ADMIN_EMAIL') || '';
            }
            modal.classList.add('open');
            toInput.focus();
        }
        function close() { modal.classList.remove('open'); }

        openBtn.addEventListener('click', open);
        cancel.addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('open')) { close(); }
        });

        sendBtn.addEventListener('click', function () {
            var to = (toInput.value || '').trim();
            if (!to) { showResult('err', 'Enter a recipient address first.'); toInput.focus(); return; }

            var mailKeys = ['MAIL_MAILER','MAIL_HOST','MAIL_PORT','MAIL_USERNAME','MAIL_PASSWORD',
                            'MAIL_SCHEME','MAIL_ENCRYPTION','MAIL_FROM_ADDRESS','MAIL_FROM_NAME'];
            var mail = {};
            mailKeys.forEach(function (k) {
                var el = document.querySelector('[name="env[' + k + ']"]');
                if (el) { mail[k] = el.value; }
            });

            sendBtn.disabled = true;
            showResult('info', 'Sending…');

            fetch('{{ route('admin.environment.test-mail') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value
                },
                body: JSON.stringify({ to: to, mail: mail })
            })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
            .then(function (res) {
                if (res.body && res.body.ok) {
                    showResult('ok', (res.body.message || 'Test email sent.') +
                        (res.body.ms != null ? ' (' + res.body.ms + ' ms)' : ''));
                } else {
                    var msg = (res.body && (res.body.error || (res.body.errors &&
                        res.body.errors.to && res.body.errors.to[0]))) || 'Send failed.';
                    showResult('err', 'Failed: ' + msg);
                }
            })
            .catch(function (e) { showResult('err', 'Request error: ' + e.message); })
            .finally(function () { sendBtn.disabled = false; });
        });
    })();
</script>
@endsection
