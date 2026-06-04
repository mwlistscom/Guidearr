<x-layouts::auth :title="__('Verify your email')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Enter your code')"
            :description="__('We emailed a 6-digit code to :email', ['email' => auth()->user()?->email])" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('verification.code') }}" class="flex flex-col gap-6">
            @csrf
            <flux:input name="code" :label="__('Verification code')" type="text"
                inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                required autofocus placeholder="123456" />

            <div class="flex flex-col gap-2">
                <x-turnstile />
                @error('cf-turnstile-response')
                    <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
                @enderror
            </div>

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Verify email') }}</flux:button>
        </form>

        <div class="flex items-center justify-center gap-4 text-sm">
            <form method="POST" action="{{ route('verification.send') }}">
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
