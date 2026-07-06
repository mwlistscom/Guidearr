<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <div id="registerFormError" class="hidden text-sm text-red-500 text-center"></div>

        <form id="registerForm" method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex flex-col gap-2">
                <x-turnstile />
                @error('cf-turnstile-response')
                    <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
                @enderror
            </div>



            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <x-social-login-buttons />

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>

    {{-- Verification-code modal: appears instantly on "Create account" so the user
         never waits on (or re-clicks through) the email send. --}}
    <div id="verifyModal" class="vm-backdrop" role="dialog" aria-modal="true" aria-labelledby="vmTitle">
        <div class="vm-card">
            {{-- Sending --}}
            <div id="vmSending" class="vm-state">
                <div class="vm-spinner" aria-hidden="true"></div>
                <p class="vm-lead">{{ __('Creating your account and emailing your code…') }}</p>
            </div>

            {{-- Enter code --}}
            <div id="vmCode" class="vm-state" hidden>
                <h2 id="vmTitle" class="vm-h">{{ __('Check your email') }}</h2>
                <p class="vm-sub">
                    {{ __('We sent a 6-digit verification code to') }}
                    <strong id="vmEmail"></strong>.
                </p>
                <p class="vm-hint">{{ __('It can take a minute to arrive — if you don\'t see it, check your spam or junk folder.') }}</p>

                <input id="vmCodeInput" class="vm-input" type="text" inputmode="numeric"
                       autocomplete="one-time-code" maxlength="6" placeholder="123456" aria-label="{{ __('Verification code') }}">

                <div id="vmCodeMsg" class="vm-msg" hidden></div>

                <div class="vm-actions">
                    <button type="button" id="vmResend" class="vm-btn vm-ghost" disabled></button>
                    <button type="button" id="vmVerify" class="vm-btn vm-primary">{{ __('Verify') }}</button>
                </div>
            </div>

            {{-- Verified --}}
            <div id="vmSuccess" class="vm-state" hidden>
                <div class="vm-check" aria-hidden="true">✓</div>
                <h2 class="vm-h">{{ __('Your email is verified') }}</h2>
                <p class="vm-sub">{{ __('Your account is ready. You can now log in.') }}</p>
                <div class="vm-actions vm-actions-center">
                    <button type="button" id="vmOk" class="vm-btn vm-primary">{{ __('OK') }}</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .vm-backdrop { position:fixed; inset:0; z-index:80; display:none;
            align-items:flex-start; justify-content:center; padding:10vh 1rem 1rem;
            background:rgba(9,9,11,.6); backdrop-filter:blur(2px); }
        .vm-backdrop.open { display:flex; }
        .vm-card { width:100%; max-width:26rem; background:#fff; color:#18181b;
            border-radius:.9rem; padding:1.6rem 1.5rem; box-shadow:0 24px 60px rgba(0,0,0,.4); }
        @media (prefers-color-scheme: dark) {
            .vm-card { background:#1c1c22; color:#e4e4e7; border:1px solid rgba(255,255,255,.08); }
        }
        .vm-state { display:flex; flex-direction:column; align-items:center; text-align:center; gap:.5rem; }
        .vm-h { font-size:1.2rem; font-weight:700; margin:0; }
        .vm-sub { font-size:.9rem; margin:0; opacity:.9; }
        .vm-hint { font-size:.8rem; margin:.15rem 0 .3rem; opacity:.65; }
        .vm-lead { font-size:.95rem; margin:.4rem 0 0; }
        .vm-input { width:100%; margin:.4rem 0; padding:.7rem; font-size:1.4rem; letter-spacing:.4rem;
            text-align:center; border-radius:.55rem; border:1px solid rgba(120,120,130,.4);
            background:transparent; color:inherit; }
        .vm-input:focus { outline:2px solid #6366f1; outline-offset:1px; }
        .vm-msg { width:100%; font-size:.82rem; padding:.5rem .6rem; border-radius:.5rem; }
        .vm-msg.err { background:rgba(248,113,113,.14); color:#dc2626; }
        .vm-msg.ok  { background:rgba(52,211,153,.16); color:#059669; }
        @media (prefers-color-scheme: dark) {
            .vm-msg.err { color:#fca5a5; } .vm-msg.ok { color:#6ee7b7; }
        }
        .vm-actions { display:flex; gap:.6rem; width:100%; margin-top:.7rem; }
        .vm-actions-center { justify-content:center; }
        .vm-btn { flex:1; padding:.65rem 1rem; border-radius:.55rem; font-size:.9rem; font-weight:600;
            cursor:pointer; border:1px solid transparent; }
        .vm-btn:disabled { opacity:.55; cursor:not-allowed; }
        .vm-primary { background:#4f46e5; color:#fff; }
        .vm-primary:hover:not(:disabled) { background:#4338ca; }
        .vm-ghost { background:transparent; border-color:rgba(120,120,130,.4); color:inherit; }
        .vm-ghost:hover:not(:disabled) { background:rgba(120,120,130,.12); }
        .vm-actions-center .vm-btn { flex:0 0 auto; min-width:8rem; }
        .vm-spinner { width:2rem; height:2rem; border-radius:50%; margin:.4rem 0;
            border:3px solid rgba(120,120,130,.3); border-top-color:#4f46e5; animation:vm-spin .8s linear infinite; }
        .vm-check { width:2.6rem; height:2.6rem; border-radius:50%; display:flex; align-items:center;
            justify-content:center; font-size:1.5rem; font-weight:700; color:#fff; background:#059669; margin:.2rem 0; }
        @keyframes vm-spin { to { transform:rotate(360deg); } }
    </style>

    <script>
        (function () {
            var form = document.getElementById('registerForm');
            if (!form || form.dataset.vmBound) { return; }
            form.dataset.vmBound = '1';

            var modal   = document.getElementById('verifyModal');
            var sBox     = document.getElementById('vmSending');
            var cBox     = document.getElementById('vmCode');
            var okBox     = document.getElementById('vmSuccess');
            var emailOut  = document.getElementById('vmEmail');
            var codeInput = document.getElementById('vmCodeInput');
            var codeMsg   = document.getElementById('vmCodeMsg');
            var resendBtn = document.getElementById('vmResend');
            var verifyBtn = document.getElementById('vmVerify');
            var okBtn     = document.getElementById('vmOk');
            var formError = document.getElementById('registerFormError');
            var submitBtn = form.querySelector('button[type="submit"]');

            var loginUrl = @json(route('login'));
            var RESEND_SECONDS = {{ \App\Models\User::VERIFICATION_RESEND_MINUTES * 60 }};
            var resendTimer = null;

            function xsrf() {
                var m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
                return m ? decodeURIComponent(m[1]) : '';
            }
            function show(box) {
                [sBox, cBox, okBox].forEach(function (b) { b.hidden = (b !== box); });
                modal.classList.add('open');
            }
            function setMsg(cls, text) {
                if (!text) { codeMsg.hidden = true; codeMsg.textContent = ''; return; }
                codeMsg.hidden = false;
                codeMsg.className = 'vm-msg ' + cls;
                codeMsg.textContent = text;
            }
            function startResendCountdown(seconds) {
                clearInterval(resendTimer);
                var remaining = seconds;
                function tick() {
                    if (remaining <= 0) {
                        clearInterval(resendTimer);
                        resendBtn.disabled = false;
                        resendBtn.textContent = @json(__('Resend code'));
                        return;
                    }
                    var m = Math.floor(remaining / 60), s = remaining % 60;
                    resendBtn.disabled = true;
                    resendBtn.textContent = @json(__('Resend in')) + ' ' + m + ':' + (s < 10 ? '0' : '') + s;
                    remaining--;
                }
                tick();
                resendTimer = setInterval(tick, 1000);
            }

            // --- Registration: show the modal immediately, submit in the background ---
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (submitBtn.disabled) { return; }
                submitBtn.disabled = true;
                formError.classList.add('hidden');
                formError.textContent = '';
                show(sBox);

                fetch(form.action, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form)
                })
                .then(function (r) {
                    return r.json().catch(function () { return {}; }).then(function (b) { return { status: r.status, body: b }; });
                })
                .then(function (res) {
                    if (res.status >= 200 && res.status < 300) {
                        emailOut.textContent = (form.querySelector('[name="email"]') || {}).value || '';
                        setMsg('', '');
                        codeInput.value = '';
                        startResendCountdown(RESEND_SECONDS);
                        show(cBox);
                        codeInput.focus();
                    } else {
                        // Validation / turnstile failure: drop the modal, surface the error on the form.
                        modal.classList.remove('open');
                        submitBtn.disabled = false;
                        var msg = res.body && res.body.errors
                            ? Object.values(res.body.errors)[0][0]
                            : (res.body && res.body.message) || @json(__('Something went wrong. Please try again.'));
                        formError.textContent = msg;
                        formError.classList.remove('hidden');
                        if (window.turnstile) { try { window.turnstile.reset(); } catch (e) {} }
                    }
                })
                .catch(function () {
                    modal.classList.remove('open');
                    submitBtn.disabled = false;
                    formError.textContent = @json(__('Network error. Please try again.'));
                    formError.classList.remove('hidden');
                });
            });

            // --- Verify the entered code ---
            function verify() {
                var code = (codeInput.value || '').trim();
                if (!code) { setMsg('err', @json(__('Enter the code from your email.'))); codeInput.focus(); return; }
                verifyBtn.disabled = true;
                setMsg('', '');
                fetch(@json(route('verification.code')), {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json',
                               'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
                    body: JSON.stringify({ code: code })
                })
                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { status: r.status, body: b }; }); })
                .then(function (res) {
                    verifyBtn.disabled = false;
                    if (res.body && res.body.ok && res.body.verified) {
                        if (res.body.redirect) { loginUrl = res.body.redirect; }
                        show(okBox);
                    } else {
                        setMsg('err', (res.body && res.body.error) || @json(__('That code is invalid or has expired.')));
                        codeInput.focus();
                        codeInput.select();
                    }
                })
                .catch(function () { verifyBtn.disabled = false; setMsg('err', @json(__('Network error. Please try again.'))); });
            }
            verifyBtn.addEventListener('click', verify);
            codeInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { verify(); } });

            // --- Resend a fresh code (5-minute cooldown) ---
            resendBtn.addEventListener('click', function () {
                if (resendBtn.disabled) { return; }
                resendBtn.disabled = true;
                setMsg('', '');
                fetch(@json(route('verification.resend')), {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() }
                })
                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { status: r.status, body: b }; }); })
                .then(function (res) {
                    if (res.body && res.body.ok) {
                        setMsg('ok', res.body.message || @json(__('A new code has been sent.')));
                        startResendCountdown(res.body.resend_available_in || RESEND_SECONDS);
                    } else {
                        setMsg('err', (res.body && res.body.error) || @json(__('Please wait before requesting another code.')));
                        startResendCountdown((res.body && res.body.retry_after) || RESEND_SECONDS);
                    }
                })
                .catch(function () { setMsg('err', @json(__('Network error. Please try again.'))); startResendCountdown(RESEND_SECONDS); });
            });

            // --- Verified → login ---
            okBtn.addEventListener('click', function () { window.location.href = loginUrl; });
        })();
    </script>
</x-layouts::auth>
