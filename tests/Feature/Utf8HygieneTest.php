<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Provider;
use App\Models\User;
use App\Services\M3uParser;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use App\Services\XmltvParser;
use App\Support\Utf8;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Provider text that is not valid UTF-8 must never reach response()->json().
 *
 * It throws InvalidArgumentException there, so ONE bad byte in ONE channel returned HTTP 500 for
 * the whole editor grid and the playlist could not be opened at all. Both real-world causes are
 * covered, using the exact bytes taken from the affected production stores.
 */
class Utf8HygieneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(storage_path('app/playlists/*.sqlite')) ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob(storage_path('app/feeds/*.sqlite')) ?: [] as $f) {
            @unlink($f);
        }
    }

    // ---- the helper ----

    public function test_windows_1252_is_decoded_to_the_right_characters(): void
    {
        $this->assertSame('AMC en Español', Utf8::clean("AMC en Espa\xf1ol"));
        $this->assertSame('Pokémon', Utf8::clean("Pok\xe9mon"));
        $this->assertSame('Aquí y Ahora', Utf8::clean("Aqu\xed y Ahora"));
        $this->assertSame('¿Qué Culpa Tiene Fatmagül?', Utf8::clean("\xbfQu\xe9 Culpa Tiene Fatmag\xfcl?"));
    }

    /**
     * 0x80-0x9F is where CP1252 keeps its punctuation and ISO-8859-1 keeps unassigned control
     * space — decoding as Latin-1 turns an apostrophe into an invisible control character.
     */
    public function test_cp1252_punctuation_survives_where_latin1_would_lose_it(): void
    {
        $this->assertSame('America’s Funniest Home Videos', Utf8::clean("America\x92s Funniest Home Videos"));
        $this->assertSame('It’s Anime', Utf8::clean("It\x92s Anime"));
        $this->assertSame('I Survived…', Utf8::clean("I Survived\x85"));
    }

    /**
     * The two faults must not be confused. Text that is real UTF-8 with a cut character at the
     * end has to lose only the stump — transcoding it as Windows-1252 double-encodes every good
     * multi-byte character in the rest of the string, which is worse than the crash it replaced.
     */
    public function test_a_truncated_tail_is_trimmed_without_mojibake(): void
    {
        // The exact shape found in providers 15/44/46/49: en-dashes intact, last one cut short.
        $good = 'US - TENNIS 05 – WTA MONTERREY AUG 24 – 11:00 PM ET';
        $broken = $good."\xe2\x80";

        $this->assertSame($good, Utf8::clean($broken));
        $this->assertStringNotContainsString('â€', Utf8::clean($broken), 'must not double-encode');
        $this->assertSame(2, substr_count(Utf8::clean($broken), '–'), 'inner en-dashes must survive');
    }

    /** Each truncation depth of a 3- and 4-byte character is a stump, and only the stump goes. */
    public function test_every_partial_multibyte_tail_is_recognised(): void
    {
        foreach (['–' => "\xe2\x80\x93", '𝄞' => "\xf0\x9d\x84\x9e"] as $char => $bytes) {
            // From two bytes up: a lead plus at least one continuation is unambiguously a stump.
            for ($keep = 2; $keep < strlen($bytes); $keep++) {
                $s = 'Chan '.substr($bytes, 0, $keep);
                $this->assertSame('Chan ', Utf8::clean($s), "partial {$char} at {$keep} byte(s)");
            }
            $this->assertSame('Chan '.$char, Utf8::clean('Chan '.$bytes));
        }
    }

    /**
     * A Windows-1252 byte near the end must NOT be mistaken for a stump — "Espa\xf1ol" ends
     * within three bytes of its last valid position, but "ol" are not continuation bytes.
     */
    public function test_cp1252_near_the_end_is_transcoded_not_trimmed(): void
    {
        $this->assertSame('AMC en Español', Utf8::clean("AMC en Espa\xf1ol"));
        $this->assertSame('Bon Appétit', Utf8::clean("Bon App\xe9tit"));
        $this->assertSame('beIN Sports XTRA ñ', Utf8::clean("beIN Sports XTRA \xf1"));
    }

    public function test_valid_utf8_is_returned_untouched(): void
    {
        foreach (['ESPN 2', 'Español', '日本語', 'Ω', '', 'a—b'] as $s) {
            $this->assertSame($s, Utf8::clean($s));
        }
        $this->assertSame('', Utf8::clean(null));
    }

    /** The regression that produced the bad bytes: substr() slicing a character in half. */
    public function test_cut_never_splits_a_multibyte_character(): void
    {
        $s = str_repeat('a', 123).'–';          // 123 + 3 bytes, cap lands mid-character
        $this->assertFalse(mb_check_encoding(substr($s, 0, 125), 'UTF-8'), 'substr() must be the broken one');

        $cut = Utf8::cut($s, 125);
        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'));
        $this->assertSame(123, strlen($cut));
        $this->assertLessThanOrEqual(125, strlen($cut));
    }

    public function test_cut_respects_the_byte_budget_and_leaves_short_strings_alone(): void
    {
        $this->assertSame('short', Utf8::cut('short', 125));
        $this->assertLessThanOrEqual(10, strlen(Utf8::cut(str_repeat('é', 50), 10)));
        $this->assertTrue(mb_check_encoding(Utf8::cut(str_repeat('é', 50), 10), 'UTF-8'));
    }

    public function test_cut_cleans_as_well_as_truncates(): void
    {
        $this->assertSame('Pokémon', Utf8::cut("Pok\xe9mon", 255));
    }

    // ---- the parsers ----

    public function test_m3u_parser_emits_valid_utf8_for_a_windows_1252_feed(): void
    {
        $m3u = "#EXTM3U\n"
            ."#EXTINF:-1 tvg-id=\"amc\" tvg-name=\"AMC en Espa\xf1ol\" group-title=\"LATINO\",AMC en Espa\xf1ol\n"
            ."http://h/1.ts\n"
            ."#EXTINF:-1 tvg-id=\"afv\" tvg-name=\"America\x92s Funniest\" group-title=\"US\",America\x92s Funniest\n"
            ."http://h/2.ts\n";

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $m3u);
        rewind($fh);
        $got = [];
        M3uParser::stream($fh, function (array $c) use (&$got) {
            $got[] = $c;
        });
        fclose($fh);

        $this->assertCount(2, $got);
        foreach ($got as $c) {
            foreach (['tvg_id', 'tvg_name', 'tvg_logo', 'group', 'name', 'url'] as $k) {
                $this->assertTrue(mb_check_encoding((string) $c[$k], 'UTF-8'), "field {$k} is not UTF-8");
            }
        }
        $this->assertSame('AMC en Español', $got[0]['tvg_name']);
        $this->assertSame('America’s Funniest', $got[1]['tvg_name']);

        // The whole payload must survive the encoder that used to throw.
        $this->assertIsString(json_encode($got, JSON_THROW_ON_ERROR));
    }

    public function test_xmltv_parser_caps_tvg_id_without_splitting_a_character(): void
    {
        $tvg = str_repeat('x', 123).'–';
        $xml = '<tv><channel id="'.$tvg.'"><display-name>Chan</display-name></channel></tv>';
        $f = tempnam(sys_get_temp_dir(), 'xmltv');
        file_put_contents($f, $xml);
        $seen = [];
        XmltvParser::stream($f, function (array $c) use (&$seen) {
            $seen[] = $c;
        }, function () {});
        @unlink($f);

        $this->assertNotEmpty($seen);
        $this->assertTrue(mb_check_encoding($seen[0]['tvg_id'], 'UTF-8'));
        $this->assertLessThanOrEqual(125, strlen($seen[0]['tvg_id']));
    }

    // ---- the crash itself ----

    /**
     * The production failure, end to end: a provider store holding the exact bytes found in
     * providers 15/44/46/48/49 must not stop the editor grid loading.
     */
    public function test_the_editor_grid_loads_a_playlist_whose_provider_store_has_bad_bytes(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $p = Provider::create(['user_id' => $u->id, 'name' => 'S', 'type' => 'm3u', 'url' => 'http://h', 'enabled' => true, 'refresh_hour' => 2]);

        $s = new ProviderStore($p->id);
        $s->begin();
        // 1. Windows-1252 name.  2. substr()-truncated tvg_id ending in a dangling lead byte.
        $s->upsertChannel(['name' => "AMC en Espa\xf1ol", 'url' => 'http://h/1.ts', 'group' => 'LATINO',
            'tvg_id' => 'amc', 'tvg_name' => "AMC en Espa\xf1ol", 'tvg_logo' => ''], 'v1');
        $s->upsertChannel(['name' => 'Tennis', 'url' => 'http://h/2.ts', 'group' => 'SPORTS',
            'tvg_id' => str_repeat('x', 123)."\xe2\x80", 'tvg_name' => 'Tennis', 'tvg_logo' => ''], 'v1');
        $s->commit();
        $s->begin();
        $o = $s->nextGroupOrder();
        foreach (['LATINO', 'SPORTS'] as $g) {
            $s->upsertGroup($g, $o, 'v1');
            $o += 10;
        }
        $s->commit();

        $pl = Playlist::create(['user_id' => $u->id, 'name' => 'PL', 'cipher' => 'utf8crash01', 'channel_start' => 100, 'enabled' => true]);
        $pl->providers()->sync([$p->id]);
        (new PlaylistStore($pl->id))->seedFromProvider($p->id, new ProviderStore($p->id));

        // Without the repair-on-read this is a 500: "Malformed UTF-8 characters".
        $res = $this->actingAs($u)->getJson("/playlists/{$pl->id}/channels?page=1&size=50");
        $res->assertOk();

        $rows = $res->json('data');
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            foreach (['name', 'tvg_name', 'tvg_id', 'tvg_logo', 'url', 'group_title'] as $k) {
                $this->assertTrue(mb_check_encoding((string) $r[$k], 'UTF-8'), "field {$k} is not UTF-8");
            }
        }
        $this->assertSame('AMC en Español', $rows[0]['name']);
    }

    /** Searching must work on the repaired text too — that is what the grid filters on. */
    public function test_search_matches_the_repaired_text(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $p = Provider::create(['user_id' => $u->id, 'name' => 'S', 'type' => 'm3u', 'url' => 'http://h', 'enabled' => true, 'refresh_hour' => 2]);
        $s = new ProviderStore($p->id);
        $s->begin();
        $s->upsertChannel(['name' => "Pok\xe9mon", 'url' => 'http://h/1.ts', 'group' => 'KIDS',
            'tvg_id' => 'poke', 'tvg_name' => "Pok\xe9mon", 'tvg_logo' => ''], 'v1');
        $s->commit();
        $s->begin();
        $s->upsertGroup('KIDS', $s->nextGroupOrder(), 'v1');
        $s->commit();

        $pl = Playlist::create(['user_id' => $u->id, 'name' => 'PL', 'cipher' => 'utf8srch001', 'channel_start' => 100, 'enabled' => true]);
        $pl->providers()->sync([$p->id]);
        (new PlaylistStore($pl->id))->seedFromProvider($p->id, new ProviderStore($p->id));

        $this->actingAs($u)->getJson("/playlists/{$pl->id}/channels?search=Pok%C3%A9mon")
            ->assertOk()->assertJsonPath('total', 1);
    }
}
