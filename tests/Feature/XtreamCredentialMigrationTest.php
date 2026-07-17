<?php

namespace Tests\Feature;

use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use App\Services\XtreamCredentialMigrator;
use App\Services\XtreamImporter;
use PDO;
use Tests\TestCase;

class XtreamCredentialMigrationTest extends TestCase
{
    /** Read raw channel rows straight from a provider store (test-only helper). */
    private function rows(int $pid): array
    {
        $db = new PDO('sqlite:'.ProviderStore::path($pid));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $db->query('SELECT id, url, type FROM channels ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function test_stream_id_is_parsed_only_from_xtream_live_urls(): void
    {
        $this->assertSame('1000137', ProviderStore::streamIdFromUrl('http://user.strmzz.cx:2082/live/ihave/secret/1000137.ts'));
        $this->assertSame('9', ProviderStore::streamIdFromUrl('http://b/live/user/pass/9.ts'));
        // encoded credential segments (rawurlencode never emits a literal '/')
        $this->assertSame('42', ProviderStore::streamIdFromUrl('http://h:80/live/a%40b.com/p%2Bx/42.ts'));

        // Not the /live/ shape -> null (left untouched by the rewrite)
        $this->assertNull(ProviderStore::streamIdFromUrl('http://manual.example/stream.m3u8'));
        $this->assertNull(ProviderStore::streamIdFromUrl('http://x/movie/u/p/5.mkv'));
        $this->assertNull(ProviderStore::streamIdFromUrl('not a url'));
    }

    public function test_rewritten_url_is_identical_to_the_importer_build(): void
    {
        $built = XtreamImporter::xtreamLiveUrl('http://b:80', 'u', 'p', '7');
        $mapped = XtreamImporter::mapStreamToChannel(['name' => 'x', 'stream_id' => '7'], [], 'http://b:80', 'u', 'p');

        // The migrator must reproduce the importer's URL exactly, so a later refresh
        // upserts in place (ON CONFLICT(url)) instead of creating new rows.
        $this->assertSame($mapped['url'], $built);
        $this->assertSame('http://b:80/live/u/p/7.ts', $built);
    }

    public function test_match_ratio_uses_unique_existing_ids_and_reports_hits(): void
    {
        $existing = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];

        $seven = XtreamCredentialMigrator::matchRatio($existing, ['1', '2', '3', '4', '5', '6', '7', '99']);
        $this->assertSame(7, $seven['hit']);
        $this->assertSame(10, $seven['existing']);
        $this->assertEqualsWithDelta(0.7, $seven['ratio'], 1e-9); // exactly on the 70% line -> passes (>=)

        $six = XtreamCredentialMigrator::matchRatio($existing, ['1', '2', '3', '4', '5', '6']);
        $this->assertEqualsWithDelta(0.6, $six['ratio'], 1e-9); // below -> would abort

        // duplicate existing ids are de-duplicated before the ratio is computed
        $dedup = XtreamCredentialMigrator::matchRatio(['a', 'a', 'b', 'c'], ['a', 'b', 'c', 'd', 'e']);
        $this->assertSame(3, $dedup['existing']);
        $this->assertSame(3, $dedup['hit']);
        $this->assertEqualsWithDelta(1.0, $dedup['ratio'], 1e-9);

        // empty store -> 0, never a divide-by-zero
        $this->assertSame(0.0, XtreamCredentialMigrator::matchRatio([], ['a'])['ratio']);
    }

    public function test_xtream_stream_ids_skips_manual_rows(): void
    {
        $pid = 99781;
        @unlink(ProviderStore::path($pid));
        $store = new ProviderStore($pid);

        $store->begin();
        foreach (['100', '200'] as $sid) {
            $store->upsertChannel([
                'name' => "Ch{$sid}", 'tvg_name' => "Ch{$sid}", 'tvg_id' => "id{$sid}", 'tvg_logo' => '',
                'group' => 'G', 'type' => 'Live', 'ext' => 'ts',
                'url' => XtreamImporter::xtreamLiveUrl('http://old:2082', 'olduser', 'oldpass', $sid),
            ], 'v1');
        }
        $store->commit();
        $store->addChannel(['name' => 'Manual', 'url' => 'http://manual.example/stream.m3u8', 'group' => 'G']);

        $ids = $store->xtreamStreamIds();
        sort($ids);
        $this->assertSame(['100', '200'], $ids); // manual row excluded

        @unlink(ProviderStore::path($pid));
    }

    public function test_rewrite_preserves_ids_swaps_urls_and_leaves_manual_untouched(): void
    {
        $pid = 99782;
        @unlink(ProviderStore::path($pid));
        $store = new ProviderStore($pid);

        $oldBase = 'http://old.example.com:2082';
        $store->begin();
        foreach (['100', '200', '300'] as $sid) {
            $store->upsertChannel([
                'name' => "Ch{$sid}", 'tvg_name' => "Ch{$sid}", 'tvg_id' => "id{$sid}", 'tvg_logo' => '',
                'group' => 'G', 'type' => 'Live', 'ext' => 'ts',
                'url' => XtreamImporter::xtreamLiveUrl($oldBase, 'olduser', 'oldpass', $sid),
            ], 'v1');
        }
        $store->commit();
        $manualId = $store->addChannel(['name' => 'Manual', 'url' => 'http://manual.example/stream.m3u8', 'group' => 'G']);

        // Snapshot: stream_id -> row id, and the manual row's url, BEFORE.
        $before = [];
        foreach ($this->rows($pid) as $r) {
            $sid = ProviderStore::streamIdFromUrl($r['url']);
            if ($sid !== null) {
                $before[$sid] = (int) $r['id'];
            }
        }
        $this->assertCount(3, $before);

        $newBase = 'http://new.example.com:9090';
        $res = $store->rewriteXtreamCredentials($newBase, 'newuser', 'newpass');

        $this->assertSame(3, $res['updated']);
        $this->assertSame(1, $res['skipped']); // the manual channel
        $this->assertSame(4, $res['total']);
        $this->assertSame(0, $res['deleted']); // no duplicate stream_ids -> nothing merged
        $this->assertSame([], $res['remap']);

        // AFTER: every stream_id keeps its exact row id (so playlist pointers survive),
        // and its URL now carries the new base + credentials.
        $afterById = [];
        $manualUrl = null;
        foreach ($this->rows($pid) as $r) {
            $afterById[(int) $r['id']] = $r;
            if ((int) $r['id'] === $manualId) {
                $manualUrl = $r['url'];
            }
        }
        foreach ($before as $sid => $id) {
            $this->assertArrayHasKey($id, $afterById, "row id {$id} for stream {$sid} must be preserved");
            $this->assertSame(
                XtreamImporter::xtreamLiveUrl($newBase, 'newuser', 'newpass', $sid),
                $afterById[$id]['url'],
            );
        }

        // The manual ('user') channel is untouched.
        $this->assertSame('http://manual.example/stream.m3u8', $manualUrl);

        @unlink(ProviderStore::path($pid));
    }

    public function test_rewrite_merges_duplicate_generations_and_returns_remap(): void
    {
        $pid = 99783;
        @unlink(ProviderStore::path($pid));
        $store = new ProviderStore($pid);

        $oldBase = 'http://old:2082';
        $newBase = 'http://new:8080';

        // Seed OLD-account rows first (lower ids), then the SAME channels on a NEW account
        // (higher ids) — the two-generation state left behind by a prior credential change.
        $store->begin();
        foreach (['100', '200', '300'] as $sid) {
            $store->upsertChannel(['name' => "O{$sid}", 'group' => 'G', 'type' => 'Live', 'ext' => 'ts',
                'url' => XtreamImporter::xtreamLiveUrl($oldBase, 'olduser', 'oldpass', $sid)], 'v1');
        }
        foreach (['100', '200', '300'] as $sid) {
            $store->upsertChannel(['name' => "N{$sid}", 'group' => 'G', 'type' => 'Live', 'ext' => 'ts',
                'url' => XtreamImporter::xtreamLiveUrl($newBase, 'newuser', 'newpass', $sid)], 'v1');
        }
        $store->commit();

        // Map stream_id -> old row id / new row id.
        $oldId = [];
        $newId = [];
        foreach ($this->rows($pid) as $r) {
            $sid = ProviderStore::streamIdFromUrl($r['url']);
            if (str_contains($r['url'], 'old:2082')) {
                $oldId[$sid] = (int) $r['id'];
            } else {
                $newId[$sid] = (int) $r['id'];
            }
        }
        $this->assertCount(6, $this->rows($pid));

        // Migrate to the NEW credentials.
        $res = $store->rewriteXtreamCredentials($newBase, 'newuser', 'newpass');

        $this->assertSame(3, $res['updated']); // each old row rewritten to the new URL
        $this->assertSame(3, $res['deleted']); // the new-account duplicate rows removed
        $this->assertSame(0, $res['skipped']);
        $this->assertSame(6, $res['total']);

        // Survivors are the OLD rows (they carry the playlist curation); the remap points each
        // removed NEW row id at its surviving OLD row id.
        foreach (['100', '200', '300'] as $sid) {
            $this->assertArrayHasKey($newId[$sid], $res['remap']);
            $this->assertSame($oldId[$sid], $res['remap'][$newId[$sid]]);
        }

        // Store now holds one row per stream_id, keyed to the old id, at the new URL.
        $after = $this->rows($pid);
        $this->assertCount(3, $after);
        foreach ($after as $r) {
            $sid = ProviderStore::streamIdFromUrl($r['url']);
            $this->assertSame($oldId[$sid], (int) $r['id']);
            $this->assertSame(XtreamImporter::xtreamLiveUrl($newBase, 'newuser', 'newpass', $sid), $r['url']);
        }

        @unlink(ProviderStore::path($pid));
    }

    public function test_remap_provider_pointers_merges_and_repoints(): void
    {
        $plid = 99790;
        @unlink(PlaylistStore::path($plid));
        $ps = new PlaylistStore($plid);

        // Insert pointers directly. Provider 3:
        //  - stream A: survivor id 1 (DISABLED) + deleted dup id 4 (enabled) -> drop dup, survivor keeps disabled
        //  - stream B: only deleted dup id 5 (enabled), no survivor pointer  -> repoint to survivor id 2
        $db = new PDO('sqlite:'.PlaylistStore::path($plid));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ins = $db->prepare('INSERT INTO playlist_channels (provider_id, channel_id, group_title, position_order, enabled, deleted) VALUES (3,:c,:g,:o,:en,0)');
        $ins->execute([':c' => 1, ':g' => 'G', ':o' => 10, ':en' => 0]); // survivor A, disabled
        $ins->execute([':c' => 4, ':g' => 'G', ':o' => 20, ':en' => 1]); // deleted dup A, enabled
        $ins->execute([':c' => 5, ':g' => 'G', ':o' => 30, ':en' => 1]); // deleted dup B, enabled (no survivor ptr)

        $r = $ps->remapProviderPointers(3, [4 => 1, 5 => 2]);

        $this->assertSame(1, $r['merged']);    // stream A: dup dropped, survivor kept
        $this->assertSame(1, $r['repointed']); // stream B repointed 5 -> 2

        $rows = $db->query('SELECT channel_id, enabled FROM playlist_channels WHERE provider_id=3 ORDER BY channel_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        // survivor 1 keeps its OWN disabled flag (0); dup 4 gone; 5 repointed to 2 (stays enabled)
        $this->assertSame([1 => 0, 2 => 1], array_map('intval', $rows));

        @unlink(PlaylistStore::path($plid));
    }
}
