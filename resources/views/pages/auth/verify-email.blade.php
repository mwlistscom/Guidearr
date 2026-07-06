<x-layouts::auth :title="__('Verify your email')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Enter your code')"
            :description="__('We emailed a 6-digit code to :email', ['email' => auth()->user()?->email])" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <flux:text class="text-center text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('It can take a minute to arrive — if you don\'t see it, check your spam or junk folder.') }}
        </flux:text>

        <form method="POST" action="{{ route('verification.code') }}" class="flex flex-col gap-6">
            @csrf
            <flux:input name="code" :label="__('Verification code')" type="text"
                inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                required autofocus placeholder="123456" />

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Verify email') }}</flux:button>
        </form>

        <div class="flex items-center justify-center gap-4 text-sm">
            <form method="POST" action="{{ route('verification.resend') }}">
                @csrf
                <flux:button type="submit" variant="ghost" class="cursor-pointer">{{ __('Resend code') }}</flux:button>
            </form>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" variant="ghost" class="cursor-pointer">{{ __('Log out') }}</flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
