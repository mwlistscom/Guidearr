<?php

namespace App\Support;

use RyanChandler\LaravelCloudflareTurnstile\Rules\Turnstile as TurnstileRule;

class Turnstile
{
    /**
     * Turnstile is active only when both keys are configured and we're not
     * running the test suite. This keeps the CAPTCHA enforced in production
     * while letting automated tests (and unconfigured installs) through.
     */
    public static function enabled(): bool
    {
        return ! app()->environment('testing')
            && filled(config('services.turnstile.key'))
            && filled(config('services.turnstile.secret'));
    }

    /**
     * Validation rules for the cf-turnstile-response field.
     * Required + verified when enabled; otherwise a no-op.
     */
    public static function rules(): array
    {
        return self::enabled() ? ['required', new TurnstileRule()] : ['nullable'];
    }
}
