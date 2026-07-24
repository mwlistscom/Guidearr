<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        {{-- @chisel-passkeys --}}
        <x-passkey-verify />
        {{-- @end-chisel-passkeys --}}

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6" data-login-form>
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <!-- Cloudflare Turnstile CAPTCHA -->
            <div class="flex flex-col gap-2">
                <x-turnstile />
                @error('cf-turnstile-response')
                    <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
                @enderror
            </div>

            <div class="flex flex-col items-center gap-2">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button" data-login-submit>
                    {{ __('Log in') }}
                </flux:button>
                <flux:text data-login-note class="hidden text-sm text-center text-zinc-500 dark:text-zinc-400">
                    {{ __('Signing in… this can take a few seconds, please wait.') }}
                </flux:text>
            </div>
        </form>

        {{-- Prevent double-submits: the Turnstile verification round-trip can take
             several seconds, and a second click would reuse the single-use token
             and fail. Disable the button and show a hint on submit. --}}
        <script>
            (function () {
                function bindLoginBusy() {
                    var form = document.querySelector('[data-login-form]');
                    if (! form || form.dataset.busyBound) return;
                    form.dataset.busyBound = '1';
                    form.addEventListener('submit', function () {
                        var btn = form.querySelector('[data-login-submit]');
                        var note = form.querySelector('[data-login-note]');
                        if (btn) {
                            btn.setAttribute('disabled', 'disabled');
                            btn.setAttribute('aria-busy', 'true');
                            btn.style.opacity = '0.65';
                            btn.style.cursor = 'wait';
                        }
                        if (note) note.classList.remove('hidden');
                    });
                }
                bindLoginBusy();
                document.addEventListener('livewire:navigated', bindLoginBusy);
            })();
        </script>

        <x-social-login-buttons />

        {{-- @chisel-registration --}}
        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
        </div>
        {{-- @end-chisel-registration --}}
    </div>
</x-layouts::auth>
