@php $providers = \App\Support\SocialLogin::enabledProviders(); @endphp
@if ($providers)
    <div class="flex flex-col gap-3">
        <div class="flex items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
            <span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
            <span>{{ __('or continue with') }}</span>
            <span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
        </div>

        @foreach ($providers as $provider => $label)
            <a href="{{ route('oauth.redirect', $provider) }}"
               class="flex items-center justify-center gap-2.5 w-full rounded-lg border border-zinc-200 px-4 py-2.5 text-sm font-medium text-zinc-800 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-800">
                @if ($provider === 'google')
                    <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.2 0 6 1.1 8.2 3.2l6.1-6.1C34.6 3 29.7 1 24 1 14.6 1 6.5 6.4 2.6 14.3l7.1 5.5C11.6 13.3 17.3 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-2.8-.4-4H24v7.6h12.7c-.3 2.1-1.6 5.2-4.7 7.3l7.2 5.6c4.3-4 6.3-9.8 6.3-16.5z"/><path fill="#FBBC05" d="M9.7 28.3c-.5-1.4-.8-2.9-.8-4.3s.3-3 .8-4.3l-7.1-5.5C1.6 17 1 20.4 1 24s.6 7 2.6 9.8l7.1-5.5z"/><path fill="#34A853" d="M24 47c6.5 0 11.9-2.1 15.9-5.8l-7.2-5.6c-2 1.3-4.6 2.3-8.7 2.3-6.7 0-12.4-3.8-14.3-9.8l-7.1 5.5C6.5 41.6 14.6 47 24 47z"/></svg>
                @elseif ($provider === 'facebook')
                    <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="#1877F2" d="M24 12c0-6.6-5.4-12-12-12S0 5.4 0 12c0 6 4.4 11 10.1 11.9v-8.4H7.1V12h3V9.4c0-3 1.8-4.6 4.5-4.6 1.3 0 2.7.2 2.7.2v2.9h-1.5c-1.5 0-1.9.9-1.9 1.8V12h3.3l-.5 3.5h-2.8v8.4C19.6 23 24 18 24 12z"/></svg>
                @endif
                <span>{{ __('Continue with :provider', ['provider' => $label]) }}</span>
            </a>
        @endforeach
    </div>
@endif
