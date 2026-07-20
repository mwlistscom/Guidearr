<?php

namespace Tests\Feature;

use App\Services\M3uProviderMigrator;
use App\Services\ProviderStore;
use PDO;
use Tests\TestCase;

class M3uUrlMigrationTest extends TestCase
{
    private function rows(int $pid): array
    {
        $db = new PDO('sqlite:'.ProviderStore::path($pid));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $db->query('SELECT id, url FROM channels ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private function seedStore(int $pid, array $urls): ProviderStore
    {
        @unlink(ProviderStore::path($pid));
        $store = new ProviderStore($pid);
        $store->begin();
        foreach ($urls as $i => $url) {
            $store->upsertChannel([
                'name' => "Ch{$i}", 'tvg_name' => "Ch{$i}", 'tvg_id' => '', 'tvg_logo' => '',
                'group' => 'G', 'type' => 'Live', 'ext' => 'ts', 'url' => $url,
            ], 'v1');
        }
        $store->commit();

        return $store;
    }

    public function test_match_key_prefers_tvg_id_then_falls_back_to_name_group(): void
    {
        $this->assertSame('id:cbc.ca', M3uProviderMigrator::matchKey('CBC.ca', 'Whatever', 'Whatever'));
        $this->assertSame('nm:foo bar|sports', M3uProviderMigrator::matchKey('', 'Foo Bar', 'Sports'));
        // case-insensitive + trimmed, both branches
        $this->assertSame(M3uProviderMigrator::matchKey('CBC.CA', 'x', 'y'), M3uProviderMigrator::matchKey('  cbc.ca ', 'x', 'y'));
        $this->assertSame(M3uProviderMigrator::matchKey('', 'ESPN HD', 'US'), M3uProviderMigrator::matchKey('', ' espn hd ', ' us '));
        // tvg-id and name keys never collide
        $this->assertNotSame(M3uProviderMigrator::matchKey('espn', '', ''), M3uProviderMigrator::matchKey('', 'espn', ''));
    }

    public function test_rewrite_in_place_preserves_ids(): void
    {
        $pid = 99801;
        $store = $this->seedStore($pid, ['http://a/1', 'http://a/2', 'http://a/3']);
        $before = $this->rows($pid);           // [1=>.../1, 2=>.../2, 3=>.../3]
        $ids = array_keys($before);

        $plan = [$ids[0] => 'http://b/1', $ids[1] => 'http://b/2', $ids[2] => 'http://b/3'];
        $res = $store->rewriteChannelUrls($plan);

        $this->assertSame(3, $res['updated']);
        $this->assertSame(0, $res['deleted']);
        $this->assertSame([], $res['remap']);
        $this->assertSame([$ids[0] => 'http://b/1', $ids[1] => 'http://b/2', $ids[2] => 'http://b/3'], $this->rows($pid));

        @unlink(ProviderStore::path($pid));
    }

    public function test_rewrite_merges_two_rows_that_target_the_same_url(): void
    {
        $pid = 99802;
        $store = $this->seedStore($pid, ['http://a/1', 'http://a/2']);
        $ids = array_keys($this->rows($pid)); // [1,2]

        // Both channels map to the SAME new URL (e.g. duplicate tvg-id in the new list).
        $res = $store->rewriteChannelUrls([$ids[0] => 'http://t', $ids[1] => 'http://t']);

        $this->assertSame(1, $res['updated']);
        $this->assertSame(1, $res['deleted']);
        $this->assertSame([$ids[1] => $ids[0]], $res['remap']); // higher id merged onto lower survivor
        $this->assertSame([$ids[0] => 'http://t'], $this->rows($pid));

        @unlink(ProviderStore::path($pid));
    }

    public function test_rewrite_handles_a_url_swap_between_two_rows(): void
    {
        $pid = 99803;
        $store = $this->seedStore($pid, ['http://x', 'http://y']);
        $ids = array_keys($this->rows($pid)); // [1(x), 2(y)]

        // Row 1 takes row 2's URL and vice versa — the temp-park path must avoid UNIQUE(url).
        $res = $store->rewriteChannelUrls([$ids[0] => 'http://y', $ids[1] => 'http://x']);

        $this->assertSame(2, $res['updated']);
        $this->assertSame(0, $res['deleted']);
        $this->assertSame([], $res['remap']);
        $this->assertSame([$ids[0] => 'http://y', $ids[1] => 'http://x'], $this->rows($pid)); // swapped, ids preserved

        @unlink(ProviderStore::path($pid));
    }

    public function test_rewrite_absorbs_an_unmatched_row_already_at_the_target(): void
    {
        $pid = 99804;
        $store = $this->seedStore($pid, ['http://a', 'http://b', 'http://c']);
        $ids = array_keys($this->rows($pid)); // [1(a), 2(b), 3(c)]

        // Row 1 should become URL 'c', which unmatched row 3 currently holds. Row 3 is NOT in
        // the plan, so it is absorbed (deleted, pointer remapped) and row 1 keeps its id.
        $res = $store->rewriteChannelUrls([$ids[0] => 'http://c']);

        $this->assertSame(1, $res['updated']);
        $this->assertSame(1, $res['deleted']);
        $this->assertSame([$ids[2] => $ids[0]], $res['remap']);
        $this->assertSame([$ids[0] => 'http://c', $ids[1] => 'http://b'], $this->rows($pid)); // id3 gone, id1+id2 kept

        @unlink(ProviderStore::path($pid));
    }
}
