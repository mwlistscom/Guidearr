<?php

namespace App\Support;

/**
 * Small helper around the social sign-in providers. A provider is "enabled" only when both
 * its client id and secret are configured (via the Environment editor / .env), so the buttons
 * appear on their own once an operator sets up the OAuth app.
 */
class SocialLogin
{
    public const PROVIDERS = ['google', 'facebook'];

    private const LABELS = ['google' => 'Google', 'facebook' => 'Facebook'];

    public static function supported(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true);
    }

    public static function label(string $provider): string
    {
        return self::LABELS[$provider] ?? ucfirst($provider);
    }

    public static function enabled(string $provider): bool
    {
        if (! self::supported($provider)) {
            return false;
        }
        $cfg = (array) config("services.{$provider}");

        return ! empty($cfg['client_id']) && ! empty($cfg['client_secret']);
    }

    /** Enabled providers as [provider => label] for the sign-in buttons. */
    public static function enabledProviders(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $p) {
            if (self::enabled($p)) {
                $out[$p] = self::label($p);
            }
        }

        return $out;
    }

    public static function anyEnabled(): bool
    {
        return self::enabledProviders() !== [];
    }
}
