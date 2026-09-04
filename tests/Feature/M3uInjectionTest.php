<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\PlaylistStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The served m3u is line-based, so a newline in a channel field ends the entry and everything
 * after it is read by the player as further directives. A channel called
 * "Sky Sports\n#EXTINF:-1,Free Movies\nhttp://…" does not render oddly — it *adds a channel*.
 *
 * This is reachable from upstream rather than only from the account holder: Xtream channel names
 * arrive as JSON, where a newline is legal, so a hostile or compromised provider can put entries
 * into a subscriber's playlist that the subscriber never chose and that survive their curation.
 *
 * These assert on the rendered playlist, not on the sanitiser, because the bug was never in the
 * helper — `attr()` existed and stripped quotes, but the display name, group and URL bypassed it
 * entirely.
 */
class M3uInjectionTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = "Sky Sports\n#EXTINF:-1,INJECTED\nhttp://attacker.example/evil.ts";

    private function servePlaylistWith(array $channel, array $playlistAttrs = []): string
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $provider = Provider::create([
            'user_id' => $user->id,
            'name' => 'P',
            'type' => 'manual',
            'enabled' => true,
        ]);

        $playlist = Playlist::create(array_merge([
            'user_id' => $user->id,
            'name' => 'PL',
            'enabled' => true,
        ], $playlistAttrs));
        $playlist->providers()->attach($provider->id);

        // Store files outlive an individual test (storage is relocated per RUN, not per test), so
        // a reused playlist id inherits another test's channels and the #EXTINF counts below stop
        // meaning anything. Start from no store at all.
        $path = PlaylistStore::path($playlist->id);
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path.$suffix)) {
                @unlink($path.$suffix);
            }
        }

        (new PlaylistStore($playlist->id))->addManualChannel($channel + [
            'name' => 'Chan',
            'url' => 'http://legit.example/real.ts',
            'group' => 'News',
        ]);

        $res = $this->get('/m3u?key='.$playlist->cipher);
        $res->assertOk();

        return $res->streamedContent();
    }

    /**
     * Assertions are made on LINES, not substrings.
     *
     * Once flattened, the payload text still reads "#EXTINF" and "http://attacker…" inside the
     * channel name — harmlessly, as part of one long title. What would matter is a *line* a
     * player parses as a directive or a stream URL, so that is what these count.
     *
     * @return list<string>
     */
    private function lines(string $body): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $body) ?: [])));
    }

    private function directiveLines(string $body, string $prefix): int
    {
        return count(array_filter($this->lines($body), fn ($l) => str_starts_with($l, $prefix)));
    }

    public function test_a_newline_in_a_channel_name_cannot_add_an_entry(): void
    {
        $body = $this->servePlaylistWith(['name' => self::PAYLOAD]);

        $this->assertNotContains(
            'http://attacker.example/evil.ts',
            $this->lines($body),
            'the attacker url became a line of its own — a player would treat it as a stream',
        );
        $this->assertSame(
            1,
            $this->directiveLines($body, '#EXTINF'),
            'one channel must produce exactly one #EXTINF line',
        );
    }

    public function test_a_newline_in_the_group_cannot_add_an_entry(): void
    {
        // group_title reaches the output twice — the group-title attribute and #EXTGRP.
        $body = $this->servePlaylistWith(['group' => self::PAYLOAD], ['extgrp_tags' => true]);

        $this->assertNotContains('http://attacker.example/evil.ts', $this->lines($body));
        $this->assertSame(1, $this->directiveLines($body, '#EXTINF'));
        $this->assertSame(1, $this->directiveLines($body, '#EXTGRP'), 'and #EXTGRP is one line too');
    }

    public function test_a_newline_in_the_url_cannot_add_an_entry(): void
    {
        $body = $this->servePlaylistWith([
            'url' => "http://legit.example/real.ts\nhttp://attacker.example/evil.ts",
        ]);

        $this->assertNotContains('http://attacker.example/evil.ts', $this->lines($body));
        $this->assertSame(1, $this->directiveLines($body, '#EXTINF'));
    }

    public function test_the_channel_still_appears_with_its_text_intact(): void
    {
        // Stripping must not throw the channel away — the name is cosmetic, and one oddly-named
        // channel is a better outcome for the viewer than one silently missing.
        $body = $this->servePlaylistWith(['name' => self::PAYLOAD]);

        $this->assertStringContainsString('http://legit.example/real.ts', $body, 'the real channel must survive');
        $this->assertStringContainsString('Sky Sports', $body, 'the legitimate part of the name is kept');
    }

    public function test_a_quote_still_cannot_close_an_attribute_early(): void
    {
        // The behaviour attr() already had, kept while adding the newline handling.
        $body = $this->servePlaylistWith(['name' => 'X', 'group' => 'News" tvg-shift="99']);

        // Scoped to the #EXTINF line, which is the only place quotes are structural. The text
        // itself survives inside the value, and a quote on an #EXTGRP line is inert — that
        // directive is a whole-line value with no attributes to break out of.
        $extinf = array_values(array_filter($this->lines($body), fn ($l) => str_starts_with($l, '#EXTINF')))[0];

        $this->assertStringNotContainsString('tvg-shift="', $extinf, 'the payload closed group-title and opened an attribute of its own');
        $this->assertSame(10, substr_count($extinf, '"'), 'five quoted attributes, so exactly ten quotes');
    }

    public function test_a_url_of_nothing_but_control_characters_is_treated_as_missing(): void
    {
        $body = $this->servePlaylistWith(['url' => "\r\n\t"]);

        $this->assertSame(0, $this->directiveLines($body, '#EXTINF'), 'no url means no entry, not a blank line');
    }
}
