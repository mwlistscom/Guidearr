<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * Stored OAuth (Google/Facebook) configuration, edited on Admin → Social and kept in the
 * settings store (storage/app/settings/app.json) under the "social" key. Client secrets are
 * encrypted at rest with the app key; hydrateServices() decrypts enabled providers into
 * config('services.*') at boot so Socialite consumes them normally.
 */
class SocialConfig
{
    private const KEY = 'social';

    /** Raw stored map: [provider => ['enabled','client_id','client_secret'(encrypted),'redirect']]. */
    private static function all(): array
    {
        $v = Settings::get(self::KEY, []);

        return is_array($v) ? $v : [];
    }

    /** Decrypted config for one provider (missing/invalid secret → empty string). */
    public static function provider(string $p): array
    {
        $c = self::all()[$p] ?? [];

        return [
            'enabled' => (bool) ($c['enabled'] ?? false),
            'client_id' => (string) ($c['client_id'] ?? ''),
            'client_secret' => self::decrypt((string) ($c['client_secret'] ?? '')),
            'redirect' => (string) ($c['redirect'] ?? ''),
        ];
    }

    /** A provider counts as enabled only when it's toggled on AND has both keys. */
    public static function enabled(string $p): bool
    {
        $c = self::provider($p);

        return $c['enabled'] && $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    public static function hasSecret(string $p): bool
    {
        return self::provider($p)['client_secret'] !== '';
    }

    /**
     * Save one provider. A blank client_secret keeps the currently stored one (so the masked
     * field can be left untouched). The secret is encrypted before it hits the store.
     */
    public static function save(string $p, array $data): void
    {
        $all = self::all();
        $prev = $all[$p] ?? [];

        $secret = trim((string) ($data['client_secret'] ?? ''));
        $storedSecret = $secret !== ''
            ? Crypt::encryptString($secret)
            : (string) ($prev['client_secret'] ?? '');

        $all[$p] = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'client_id' => trim((string) ($data['client_id'] ?? '')),
            'client_secret' => $storedSecret,
            'redirect' => trim((string) ($data['redirect'] ?? '')),
        ];

        Settings::set(self::KEY, $all);
    }

    /** Push enabled+keyed providers into config('services.*') so Socialite reads them. */
    public static function hydrateServices(): void
    {
        foreach (SocialLogin::PROVIDERS as $p) {
            try {
                if (! self::enabled($p)) {
                    continue;
                }
                $c = self::provider($p);
                config([
                    "services.{$p}.client_id" => $c['client_id'],
                    "services.{$p}.client_secret" => $c['client_secret'],
                    "services.{$p}.redirect" => $c['redirect'] !== '' ? $c['redirect'] : null,
                ]);
            } catch (\Throwable $e) {
                // A fresh or unreadable store must never break application boot.
            }
        }
    }

    /** The URLs an operator must register in the Google/Meta consoles. */
    public static function callbackUrls(): array
    {
        $base = rtrim(Settings::linksBaseUrl() ?: url('/'), '/');

        return [
            'base' => $base,
            'google' => "{$base}/auth/google/callback",
            'facebook' => "{$base}/auth/facebook/callback",
            'facebook_data_deletion' => "{$base}/data-deletion/facebook",
            'privacy' => "{$base}/privacy",
        ];
    }

    private static function decrypt(string $enc): string
    {
        if ($enc === '') {
            return '';
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
