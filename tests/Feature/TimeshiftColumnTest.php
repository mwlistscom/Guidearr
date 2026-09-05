<?php

namespace Tests\Feature;

use App\Services\ProviderValidator;
use Tests\TestCase;

/**
 * `server_info.timezone`, as sent by an Xtream server, is written straight into
 * `providers.timeshift` — a varchar(64) — from three places: provider create, provider update,
 * and every refresh in FeedWork. All three read the same `parseXtream()` return value.
 *
 * A server that answered with something longer than that produced an unhandled
 * "Data too long for column 'timeshift'" and a 500, so the operator saw a provider fail to save
 * when its credentials were perfectly good. It happened on this deployment on 2026-07-27.
 *
 * The value is optional: dropping it costs a guide-time offset, whereas letting it through costs
 * the entire provider. So anything unstorable becomes null rather than an exception.
 */
class TimeshiftColumnTest extends TestCase
{
    private function tz(mixed $timezone): ?string
    {
        return ProviderValidator::parseXtream(json_encode([
            'user_info' => ['auth' => 1],
            'server_info' => ['timezone' => $timezone],
        ]))['timeshift'];
    }

    public function test_a_real_timezone_is_kept_exactly(): void
    {
        // The values actually seen in production, plus the longest IANA identifier there is (32).
        foreach (['UTC', 'Europe/London', 'Europe/Amsterdam', '+0000', 'America/Argentina/ComodRivadavia'] as $tz) {
            $this->assertSame($tz, $this->tz($tz), "{$tz} is a legitimate value and must survive");
        }
    }

    public function test_a_value_too_long_for_the_column_is_dropped_not_stored(): void
    {
        // 65 characters — one past the column, and the shape of the original failure.
        $this->assertNull($this->tz(str_repeat('a', 65)));

        // Exactly at the limit still fits.
        $this->assertSame(str_repeat('a', 64), $this->tz(str_repeat('a', 64)));
    }

    public function test_the_limit_is_counted_in_characters_like_the_column_is(): void
    {
        // MySQL counts a utf8mb4 varchar in characters, so a multi-byte string of 64 characters
        // fits even though it is 128 bytes. Using strlen() here would throw it away needlessly.
        $multibyte = str_repeat('é', 64);

        $this->assertSame(128, strlen($multibyte));
        $this->assertSame($multibyte, $this->tz($multibyte));
        $this->assertNull($this->tz(str_repeat('é', 65)));
    }

    public function test_junk_that_is_not_a_string_at_all_is_dropped(): void
    {
        // data_get() returns whatever the JSON held — a misbehaving server can send an object or
        // an array where a string was expected, and casting that to string would itself error.
        $this->assertNull($this->tz(['unexpected' => 'array']));
        $this->assertNull($this->tz(null));
        $this->assertNull($this->tz(''));
        $this->assertNull($this->tz('   '));
    }

    public function test_control_characters_are_stripped(): void
    {
        // timeshift is echoed into provider logs and the guide path; a newline there would break
        // the line the same way it did in the served m3u.
        $this->assertSame('UTC', $this->tz("UTC\n"));
        $this->assertSame('Europe/London', $this->tz("Europe/\x00London"));
    }

    public function test_a_failed_login_still_reports_no_timezone(): void
    {
        $this->assertNull(ProviderValidator::parseXtream('{"user_info":{"auth":0}}')['timeshift']);
        $this->assertNull(ProviderValidator::parseXtream('not json')['timeshift']);
    }
}
