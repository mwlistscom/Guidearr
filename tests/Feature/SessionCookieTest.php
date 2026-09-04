<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The session cookie's Secure flag.
 *
 * Laravel leaves `secure` unset by default, and Guidearr is normally deployed with TLS
 * terminated at a proxy in front — so the app itself sees plain HTTP over the compose network
 * and cannot infer that the browser is on HTTPS. The result was a session cookie with no
 * Secure flag on every install that had not set SESSION_SECURE_COOKIE by hand, and one stray
 * http:// request would put it on the wire.
 *
 * It is derived from APP_URL instead. That is right without anything to configure, and leaves
 * an install genuinely served over http working — forcing the flag there would stop anyone
 * logging in at all, which is a worse failure than the one being fixed.
 */
class SessionCookieTest extends TestCase
{
    private function secureFor(?string $appUrl, ?string $override = null): bool
    {
        // config/session.php reads env() directly, so exercise the same expression it uses
        // rather than asserting on a config value the test harness has already resolved.
        $explicit = $override !== null ? filter_var($override, FILTER_VALIDATE_BOOL) : null;

        return $explicit ?? str_starts_with(strtolower((string) $appUrl), 'https://');
    }

    public function test_the_config_derives_secure_from_app_url(): void
    {
        $src = (string) file_get_contents(config_path('session.php'));

        $this->assertStringContainsString(
            "env('SESSION_SECURE_COOKIE'",
            $src,
            'the override must still be honoured',
        );

        $this->assertMatchesRegularExpression(
            "/'secure'\s*=>\s*env\('SESSION_SECURE_COOKIE',\s*str_starts_with/",
            $src,
            'secure must default from APP_URL, not be left unset',
        );

        $this->assertStringNotContainsString(
            "'secure' => env('SESSION_SECURE_COOKIE'),",
            $src,
            'leaving it unset is the bug: no Secure flag behind a TLS-terminating proxy',
        );
    }

    public function test_an_https_app_url_marks_the_cookie_secure(): void
    {
        $this->assertTrue($this->secureFor('https://guidearr.example.com:7979'));
        $this->assertTrue($this->secureFor('HTTPS://guidearr.example.com'));
    }

    public function test_a_plain_http_install_still_works(): void
    {
        // Forcing Secure here would stop anyone logging in — a worse failure than the leak.
        $this->assertFalse($this->secureFor('http://localhost:7979'));
        $this->assertFalse($this->secureFor(null));
    }

    public function test_the_env_override_still_wins_in_both_directions(): void
    {
        $this->assertTrue($this->secureFor('http://localhost', 'true'));
        $this->assertFalse($this->secureFor('https://guidearr.example.com', 'false'));
    }
}
