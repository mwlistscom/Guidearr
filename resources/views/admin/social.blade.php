@extends('admin.layout')
@section('title', 'Social')
@section('content')
<style>
    .social-card { max-width: 40rem; margin-bottom: 1.6rem; }
    .social-head { display: flex; align-items: center; gap: .6rem; margin-bottom: .2rem; }
    .social-head svg { width: 22px; height: 22px; }
    .social-head h2 { margin: 0; }
    .social-enable { display: inline-flex; align-items: center; gap: .5rem; margin: .3rem 0 1rem; font-weight: 600; cursor: pointer; }
    .social-fields { display: flex; flex-direction: column; gap: .9rem; transition: opacity .15s; }
    .social-fields.disabled { opacity: .45; }
    .social-field label { display: block; font-size: .82rem; color: #c4c4cc; margin-bottom: .3rem; }
    .social-field input[type=text], .social-field input[type=password] {
        width: 100%; box-sizing: border-box; background: #0e0f13; color: var(--text);
        border: 1px solid rgba(255,255,255,.16); border-radius: .5rem; padding: .5rem .7rem; font-size: .88rem; }
    .social-field input:disabled { cursor: not-allowed; }
    .setup-toggle { background: transparent; border: 1px solid rgba(255,255,255,.16); color: var(--text);
        border-radius: .45rem; padding: .35rem .7rem; font-size: .83rem; cursor: pointer; margin-top: 1rem; }
    .setup-toggle:hover { border-color: var(--accent); }
    .setup { display: none; margin-top: .9rem; padding: .9rem 1rem; border: 1px solid rgba(255,255,255,.10);
        border-radius: .6rem; background: #16171a; font-size: .85rem; color: #cdd2da; }
    .setup.open { display: block; }
    .setup ol { margin: .4rem 0 0; padding-left: 1.2rem; }
    .setup li { margin: .35rem 0; }
    .url-row { display: flex; align-items: center; gap: .5rem; margin: .3rem 0; flex-wrap: wrap; }
    .url-row code { background: #0e0f13; border: 1px solid rgba(255,255,255,.12); border-radius: .35rem;
        padding: .18rem .45rem; font-size: .8rem; word-break: break-all; }
    .copy-btn { background: transparent; border: 1px solid rgba(255,255,255,.16); color: #aab; border-radius: .35rem;
        padding: .12rem .5rem; font-size: .74rem; cursor: pointer; }
    .copy-btn:hover { color: #fff; border-color: var(--accent); }
    .social-note { color: var(--muted); font-size: .82rem; margin: .2rem 0 1.4rem; }
</style>

<h1>Social sign-in</h1>
<p class="muted">Let visitors sign in with Google or Facebook. Enable a provider, paste its OAuth credentials,
    and register the callback URL shown under <strong>How to set it up</strong> in that provider's console.
    Secrets are stored encrypted.</p>

@unless ($linksBaseSet)
    <p class="social-note">⚠️ Set your public <strong>Links base URL</strong> on the <a href="{{ route('admin.config') }}" style="color:var(--accent)">Config</a> page so the callback URLs below are correct behind a reverse proxy. Right now they're derived from this request.</p>
@endunless

<form method="POST" action="{{ route('admin.social.update') }}" autocomplete="off">
    @csrf
    @method('PUT')

    @foreach ($providers as $provider => $data)
        @php $enabled = old("$provider.enabled", $data['enabled']); @endphp
        <div class="card social-card">
            <div class="social-head">
                @if ($provider === 'google')
                    <svg viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.2 0 6 1.1 8.2 3.2l6.1-6.1C34.6 3 29.7 1 24 1 14.6 1 6.5 6.4 2.6 14.3l7.1 5.5C11.6 13.3 17.3 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-2.8-.4-4H24v7.6h12.7c-.3 2.1-1.6 5.2-4.7 7.3l7.2 5.6c4.3-4 6.3-9.8 6.3-16.5z"/><path fill="#FBBC05" d="M9.7 28.3c-.5-1.4-.8-2.9-.8-4.3s.3-3 .8-4.3l-7.1-5.5C1.6 17 1 20.4 1 24s.6 7 2.6 9.8l7.1-5.5z"/><path fill="#34A853" d="M24 47c6.5 0 11.9-2.1 15.9-5.8l-7.2-5.6c-2 1.3-4.6 2.3-8.7 2.3-6.7 0-12.4-3.8-14.3-9.8l-7.1 5.5C6.5 41.6 14.6 47 24 47z"/></svg>
                @else
                    <svg viewBox="0 0 24 24"><path fill="#1877F2" d="M24 12c0-6.6-5.4-12-12-12S0 5.4 0 12c0 6 4.4 11 10.1 11.9v-8.4H7.1V12h3V9.4c0-3 1.8-4.6 4.5-4.6 1.3 0 2.7.2 2.7.2v2.9h-1.5c-1.5 0-1.9.9-1.9 1.8V12h3.3l-.5 3.5h-2.8v8.4C19.6 23 24 18 24 12z"/></svg>
                @endif
                <h2>{{ $data['label'] }}</h2>
            </div>

            <label class="social-enable">
                <input type="checkbox" name="{{ $provider }}[enabled]" value="1" data-enable="{{ $provider }}" {{ $enabled ? 'checked' : '' }}>
                {{ __('Enable :provider sign-in', ['provider' => $data['label']]) }}
            </label>

            <div class="social-fields {{ $enabled ? '' : 'disabled' }}" data-fields="{{ $provider }}">
                <div class="social-field">
                    <label>{{ $provider === 'google' ? 'Client ID' : 'App ID' }}</label>
                    <input type="text" name="{{ $provider }}[client_id]" value="{{ old("$provider.client_id", $data['client_id']) }}" {{ $enabled ? '' : 'disabled' }}>
                </div>
                <div class="social-field">
                    <label>{{ $provider === 'google' ? 'Client secret' : 'App secret' }}</label>
                    <input type="password" name="{{ $provider }}[client_secret]" placeholder="{{ $data['has_secret'] ? '•••••••• saved — leave blank to keep' : '' }}" {{ $enabled ? '' : 'disabled' }}>
                </div>
                <div class="social-field">
                    <label>Redirect URI <span class="muted" style="font-weight:400">— must match the console</span></label>
                    <input type="text" name="{{ $provider }}[redirect]" value="{{ old("$provider.redirect", $data['redirect'] ?: $urls[$provider]) }}" {{ $enabled ? '' : 'disabled' }}>
                </div>
            </div>

            <button type="button" class="setup-toggle" data-setup="{{ $provider }}">{{ __('How to set it up') }} ▾</button>
            <div class="setup" data-setup-body="{{ $provider }}">
                @if ($provider === 'google')
                    <ol>
                        <li>Open the <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" style="color:var(--accent)">Google Cloud Console → Credentials</a> and create an <strong>OAuth client ID</strong> (type: Web application).</li>
                        <li>Add this <strong>Authorized redirect URI</strong>:
                            <div class="url-row"><code>{{ $urls['google'] }}</code><button type="button" class="copy-btn" data-copy="{{ $urls['google'] }}">Copy</button></div>
                        </li>
                        <li>On the OAuth consent screen, set the <strong>Privacy policy URL</strong>:
                            <div class="url-row"><code>{{ $urls['privacy'] }}</code><button type="button" class="copy-btn" data-copy="{{ $urls['privacy'] }}">Copy</button></div>
                        </li>
                        <li>Paste the <strong>Client ID</strong> and <strong>Client secret</strong> above, tick <em>Enable</em>, and Save.</li>
                    </ol>
                @else
                    <ol>
                        <li>Create an app at <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener" style="color:var(--accent)">Meta for Developers</a> and add the <strong>Facebook Login</strong> product.</li>
                        <li><strong>Facebook Login → Settings</strong>: turn on <strong>Client OAuth Login</strong> and <strong>Web OAuth Login</strong>, then add this exact <strong>Valid OAuth Redirect URI</strong> — this is what lets sign-in work (without it Facebook shows <em>"URL Blocked"</em>):
                            <div class="url-row"><code>{{ $urls['facebook'] }}</code><button type="button" class="copy-btn" data-copy="{{ $urls['facebook'] }}">Copy</button></div>
                        </li>
                        <li><strong>Settings → Basic → User Data Deletion</strong>: pick <em>one</em>:
                            <div style="margin:.3rem 0 .1rem">• <em>Data Deletion Callback URL</em> (automatic — a server-to-server endpoint, not a page you open):</div>
                            <div class="url-row"><code>{{ $urls['facebook_data_deletion'] }}</code><button type="button" class="copy-btn" data-copy="{{ $urls['facebook_data_deletion'] }}">Copy</button></div>
                            <div style="margin:.3rem 0 .1rem">• <em>or Data Deletion Instructions URL</em> (a help page):</div>
                            <div class="url-row"><code>{{ $urls['data_deletion_instructions'] }}</code><button type="button" class="copy-btn" data-copy="{{ $urls['data_deletion_instructions'] }}">Copy</button></div>
                        </li>
                        <li>Also on <strong>Settings → Basic</strong>: add <code>{{ parse_url($urls['base'], PHP_URL_HOST) }}</code> under <strong>App Domains</strong>, and set the <strong>Privacy Policy URL</strong>:
                            <div class="url-row"><code>{{ $urls['privacy'] }}</code><button type="button" class="copy-btn" data-copy="{{ $urls['privacy'] }}">Copy</button></div>
                        </li>
                        <li>Paste the <strong>App ID</strong> and <strong>App secret</strong> above, tick <em>Enable Facebook sign-in</em>, and Save.</li>
                    </ol>
                @endif
            </div>
        </div>
    @endforeach

    <button type="submit">Save changes</button>
</form>

<script>
    // Gray out a provider's fields unless its Enable box is checked.
    document.querySelectorAll('[data-enable]').forEach(function (box) {
        var apply = function () {
            var wrap = document.querySelector('[data-fields="' + box.dataset.enable + '"]');
            if (!wrap) return;
            wrap.classList.toggle('disabled', !box.checked);
            wrap.querySelectorAll('input').forEach(function (i) { i.disabled = !box.checked; });
        };
        box.addEventListener('change', apply);
        apply();
    });
    // Toggle the setup instructions.
    document.querySelectorAll('[data-setup]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var body = document.querySelector('[data-setup-body="' + btn.dataset.setup + '"]');
            if (body) body.classList.toggle('open');
        });
    });
    // Copy-to-clipboard.
    document.querySelectorAll('.copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            navigator.clipboard.writeText(btn.dataset.copy).then(function () {
                var t = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = t; }, 1200);
            });
        });
    });
</script>
@endsection
