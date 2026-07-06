<?php

use App\Concerns\PasswordValidationRules;
use App\Models\SocialAccount;
use App\Support\SocialLogin;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Connected accounts')] class extends Component
{
    use PasswordValidationRules;

    public array $linked = [];

    public array $available = [];

    public bool $hasPassword = false;

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->refreshState();
    }

    private function refreshState(): void
    {
        $user = Auth::user();
        $this->hasPassword = filled($user->password);

        $accounts = $user->socialAccounts()->orderBy('provider')->get();
        $this->linked = $accounts->map(fn (SocialAccount $a) => [
            'id' => $a->id,
            'label' => SocialLogin::label($a->provider),
        ])->all();

        $this->available = array_diff_key(
            SocialLogin::enabledProviders(),
            array_flip($accounts->pluck('provider')->all())
        );
    }

    /** True when unlinking this account would leave the user with no way to sign in. */
    private function isOnlyLoginMethod(SocialAccount $account): bool
    {
        $user = Auth::user();

        return blank($user->password)
            && ! $user->socialAccounts()->whereKeyNot($account->id)->exists();
    }

    public function unlink(int $id): void
    {
        $account = Auth::user()->socialAccounts()->whereKey($id)->first();
        if (! $account) {
            return;
        }
        if ($this->isOnlyLoginMethod($account)) {
            Flux::toast(variant: 'danger', text: __('Set a password first — this is your only way to sign in.'));

            return;
        }
        $label = SocialLogin::label($account->provider);
        $account->delete();
        $this->refreshState();
        Flux::toast(variant: 'success', text: __(':provider disconnected.', ['provider' => $label]));
    }

    public function setPassword(): void
    {
        if ($this->hasPassword) {
            return; // an existing password is changed on the Security page
        }
        $validated = $this->validate(['password' => $this->passwordRules()]);
        Auth::user()->update(['password' => $validated['password']]);
        $this->reset('password', 'password_confirmation');
        $this->refreshState();
        Flux::toast(variant: 'success', text: __('Password set — you can now sign in with your email as well.'));
    }
}; ?>

<section class="w-full">
    <flux:heading class="sr-only">{{ __('Connected accounts') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Connected accounts')" :subheading="__('Sign in faster with Google or Facebook, or manage your existing connections')">
        @if (session('status'))
            <div class="mb-4 rounded-lg bg-green-50 px-4 py-2 text-sm text-green-700 dark:bg-green-900/20 dark:text-green-300">{{ session('status') }}</div>
        @endif
        @error('provider')
            <div class="mb-4 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300">{{ $message }}</div>
        @enderror

        <div class="flex flex-col gap-3">
            @forelse ($linked as $account)
                <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:text class="font-medium">{{ $account['label'] }}</flux:text>
                    <flux:button size="sm" variant="ghost" wire:click="unlink({{ $account['id'] }})"
                        wire:confirm="{{ __('Disconnect :provider from your account?', ['provider' => $account['label']]) }}">
                        {{ __('Disconnect') }}
                    </flux:button>
                </div>
            @empty
                <flux:text variant="subtle">{{ __('No connected accounts yet.') }}</flux:text>
            @endforelse
        </div>

        @if (! empty($available))
            <flux:separator class="my-6" variant="subtle" />
            <flux:subheading>{{ __('Connect another') }}</flux:subheading>
            <div class="mt-3 flex flex-col gap-3">
                @foreach ($available as $provider => $label)
                    <flux:button :href="route('oauth.redirect', $provider)" variant="outline" class="w-full">
                        {{ __('Connect :provider', ['provider' => $label]) }}
                    </flux:button>
                @endforeach
            </div>
        @endif

        @unless ($hasPassword)
            <flux:separator class="my-6" variant="subtle" />
            <flux:subheading>{{ __('Set a password') }}</flux:subheading>
            <flux:text variant="subtle" class="mt-1">{{ __('Your account signs in through a connected provider only. Set a password to also sign in with your email — and so you can safely disconnect a provider.') }}</flux:text>
            <form wire:submit="setPassword" class="mt-4 flex flex-col gap-4">
                <flux:input wire:model="password" :label="__('New password')" type="password" viewable autocomplete="new-password" />
                <flux:input wire:model="password_confirmation" :label="__('Confirm password')" type="password" viewable autocomplete="new-password" />
                <div>
                    <flux:button variant="primary" type="submit">{{ __('Set password') }}</flux:button>
                </div>
            </form>
        @endunless
    </x-pages::settings.layout>
</section>
