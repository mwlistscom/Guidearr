<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use App\Services\ProviderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_guests_cannot_access_providers(): void
    {
        $this->get('/providers')->assertRedirect('/login');
    }

    public function test_user_can_create_a_manual_provider_and_see_it_in_the_grid_without_password(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/providers', [
            'name' => 'My Manual', 'type' => 'manual', 'myshift' => 2, 'enabled' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('providers', ['name' => 'My Manual', 'user_id' => $user->id, 'type' => 'manual']);

        $data = $this->actingAs($user)->getJson('/providers/data')->assertOk()->json();
        $this->assertCount(1, $data);
        $this->assertArrayNotHasKey('password', $data[0]); // grid never exposes secrets
        $this->assertSame(2, $data[0]['myshift']);
    }

    public function test_refresh_hour_and_minute_default_into_the_1_to_3_window(): void
    {
        $p = Provider::create(['user_id' => $this->user()->id, 'name' => 'X', 'type' => 'manual']);
        $this->assertTrue($p->refresh_hour >= 1 && $p->refresh_hour <= 3);
        $this->assertTrue($p->refresh_minute >= 0 && $p->refresh_minute <= 59);
    }

    public function test_explicit_refresh_hour_is_honored(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson('/providers', [
            'name' => 'Fixed', 'type' => 'manual', 'refresh_hour' => 17,
        ])->assertCreated();
        $this->assertSame(17, Provider::where('name', 'Fixed')->first()->refresh_hour);
    }

    public function test_auto_refresh_hour_on_edit_rerandomizes_into_window(): void
    {
        $user = $this->user();
        $p = Provider::create(['user_id' => $user->id, 'name' => 'X', 'type' => 'manual', 'refresh_hour' => 17]);
        $this->actingAs($user)->putJson("/providers/{$p->id}", [
            'name' => 'X', 'type' => 'manual', 'refresh_hour' => null,
        ])->assertOk();
        $fresh = $p->fresh();
        $this->assertTrue($fresh->refresh_hour >= 1 && $fresh->refresh_hour <= 3);
    }

    public function test_password_is_encrypted_at_rest_but_readable_by_owner(): void
    {
        $p = Provider::create([
            'user_id' => $this->user()->id, 'name' => 'X', 'type' => 'manual', 'password' => 'sup3rsecret',
        ]);
        $raw = \DB::table('providers')->where('id', $p->id)->value('password');
        $this->assertNotSame('sup3rsecret', $raw);            // stored ciphertext
        $this->assertSame('sup3rsecret', $p->fresh()->password); // decrypts via cast
    }

    public function test_toggle_flips_enabled(): void
    {
        $user = $this->user();
        $p = Provider::create(['user_id' => $user->id, 'name' => 'X', 'type' => 'manual', 'enabled' => false]);
        $this->actingAs($user)->postJson("/providers/{$p->id}/toggle")->assertOk()->assertJson(['enabled' => true]);
    }

    public function test_creating_a_provider_enqueues_a_feed_job(): void
    {
        $user = $this->user();
        $msgid = $this->actingAs($user)->postJson('/providers', [
            'name' => 'M', 'type' => 'manual',
        ])->assertCreated()->json('msgid');

        $this->assertNotEmpty($msgid);
        $this->assertDatabaseHas('feed_queue', ['msgid' => $msgid, 'state' => 'queued', 'user_id' => $user->id]);
        $this->assertDatabaseHas('feed_logs', ['msgid' => $msgid]);
    }

    public function test_refresh_requeues_and_is_one_row_per_provider(): void
    {
        $user = $this->user();
        $p = \App\Models\Provider::create(['user_id' => $user->id, 'name' => 'X', 'type' => 'manual']);

        $m1 = \App\Models\FeedQueue::enqueue($p)->msgid;
        $m2 = $this->actingAs($user)->postJson("/providers/{$p->id}/refresh")->assertOk()->json('msgid');

        $this->assertNotSame($m1, $m2);
        $this->assertSame(1, \App\Models\FeedQueue::where('provider_id', $p->id)->count()); // upsert, not duplicate
        $this->assertSame('queued', \App\Models\FeedQueue::where('provider_id', $p->id)->first()->state);
    }

    public function test_feed_poll_is_owner_scoped_and_returns_lines(): void
    {
        $user = $this->user();
        $p = \App\Models\Provider::create(['user_id' => $user->id, 'name' => 'X', 'type' => 'manual']);
        $job = \App\Models\FeedQueue::enqueue($p);

        $body = $this->actingAs($user)->getJson("/providers/feed/{$job->msgid}")->assertOk()->json();
        $this->assertSame('queued', $body['state']);
        $this->assertNotEmpty($body['logs']);

        $this->actingAs($this->user())->getJson("/providers/feed/{$job->msgid}")->assertForbidden();
    }

    public function test_worker_processes_a_manual_job_to_done(): void
    {
        $user = $this->user();
        $p = \App\Models\Provider::create(['user_id' => $user->id, 'name' => 'X', 'type' => 'manual']);
        $job = \App\Models\FeedQueue::enqueue($p);

        $this->artisan('feed:work', ['--once' => true])->assertSuccessful();

        $this->assertSame('done', $job->fresh()->state);
        $this->assertSame('ok', $p->fresh()->last_status);
        $this->assertNotNull($p->fresh()->last_refresh_at);
        $this->assertIsInt($job->fresh()->elapsed);
        $this->assertGreaterThanOrEqual(0, $job->fresh()->elapsed);
    }

    public function test_trim_removes_old_logs(): void
    {
        $user = $this->user();
        $p = \App\Models\Provider::create(['user_id' => $user->id, 'name' => 'X', 'type' => 'manual']);
        \App\Models\FeedLog::write('oldmsg00', $p->id, $user->id, 'info', 'old');
        \App\Models\FeedLog::where('msgid', 'oldmsg00')->update(['created_at' => now()->subDays(40)]);
        \App\Models\FeedLog::write('newmsg00', $p->id, $user->id, 'info', 'new');

        $this->artisan('feed:trim', ['--days' => 14])->assertSuccessful();

        $this->assertDatabaseMissing('feed_logs', ['msgid' => 'oldmsg00']);
        $this->assertDatabaseHas('feed_logs', ['msgid' => 'newmsg00']);
    }

    public function test_owner_only_access(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $p = Provider::create(['user_id' => $owner->id, 'name' => 'X', 'type' => 'manual']);

        $this->actingAs($other)->getJson("/providers/{$p->id}")->assertForbidden();
        $this->actingAs($other)->deleteJson("/providers/{$p->id}")->assertForbidden();
        $this->actingAs($owner)->getJson("/providers/{$p->id}")->assertOk()->assertJsonPath('name', 'X');
    }

    public function test_xtream_requires_username_and_password(): void
    {
        $this->actingAs($this->user())->postJson('/providers', [
            'name' => 'Bad Xtream', 'type' => 'xtream', 'url' => 'http://example.com:8080',
        ])->assertStatus(422);
    }

    public function test_inline_cell_edit_updates_allowed_fields_and_rejects_others(): void
    {
        $user = $this->user();
        $p = Provider::create(['user_id' => $user->id, 'name' => 'Old', 'type' => 'm3u', 'url' => 'http://a.example/x.m3u']);

        $this->actingAs($user)->patchJson("/providers/{$p->id}/cell", ['field' => 'name', 'value' => 'New'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('New', $p->fresh()->name);

        $this->actingAs($user)->patchJson("/providers/{$p->id}/cell", ['field' => 'url', 'value' => 'not a url'])
            ->assertStatus(422);
        $this->assertSame('http://a.example/x.m3u', $p->fresh()->url);

        $this->actingAs($user)->patchJson("/providers/{$p->id}/cell", ['field' => 'password', 'value' => 'x'])
            ->assertStatus(422);

        $this->actingAs($this->user())->patchJson("/providers/{$p->id}/cell", ['field' => 'name', 'value' => 'Hax'])
            ->assertForbidden();
    }

    public function test_m3u_parser_extracts_attributes_and_classifies(): void
    {
        $ext = \App\Services\M3uParser::parseExtinf('#EXTINF:-1 tvg-id="cnn.us" tvg-name="CNN" tvg-logo="http://x/c.png" group-title="News",CNN HD');
        $this->assertSame('cnn.us', $ext['tvg_id']);
        $this->assertSame('CNN', $ext['tvg_name']);
        $this->assertSame('News', $ext['group']);
        $this->assertSame('CNN HD', $ext['name']);

        $this->assertSame('VOD', \App\Services\M3uParser::classify('http://h/movie/1.mkv')['type']);
        $this->assertSame('Live', \App\Services\M3uParser::classify('http://h:8080/live/123')['type']);
    }

    public function test_m3u_parser_streams_channels_and_groups(): void
    {
        $m3u = "#EXTM3U\n"
            . "#EXTINF:-1 tvg-id=\"a\" group-title=\"News\",A\nhttp://h/a.ts\n"
            . "#EXTINF:-1 tvg-id=\"b\" group-title=\"Sports\",B\nhttp://h/b.ts\n";
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $m3u); rewind($fh);
        $seen = [];
        $r = \App\Services\M3uParser::stream($fh, function ($c) use (&$seen) { $seen[] = $c['name']; });
        fclose($fh);
        $this->assertSame(2, $r['count']);
        $this->assertEqualsCanonicalizing(['News', 'Sports'], $r['groups']);
        $this->assertSame(['A', 'B'], $seen);
    }

    public function test_provider_store_upserts_and_sweeps(): void
    {
        $pid = 999001;
        @unlink(\App\Services\ProviderStore::path($pid));
        $store = new \App\Services\ProviderStore($pid);

        $store->begin();
        $store->upsertChannel(['name' => 'A', 'url' => 'http://h/a.ts', 'group' => 'News'], 'v1');
        $store->upsertChannel(['name' => 'B', 'url' => 'http://h/b.ts', 'group' => 'News'], 'v1');
        $store->commit();
        $this->assertSame(2, $store->counts()['channels']);

        // next run only sees A across four sweeps -> B accumulates misses and is pruned
        for ($i = 0; $i < 4; $i++) {
            $store->begin();
            $store->upsertChannel(['name' => 'A', 'url' => 'http://h/a.ts', 'group' => 'News'], 'v' . ($i + 2));
            $store->commit();
            $store->sweep('v' . ($i + 2));
        }
        $this->assertSame(1, $store->counts()['channels']); // B swept away

        @unlink(\App\Services\ProviderStore::path($pid));
    }

    public function test_toggle_enqueues_on_enable_and_cancels_on_disable(): void
    {
        $user = $this->user();
        $p = \App\Models\Provider::create(['user_id' => $user->id, 'name' => 'X', 'type' => 'manual', 'enabled' => false]);

        $this->actingAs($user)->postJson("/providers/{$p->id}/toggle")->assertOk()->assertJson(['enabled' => true]);
        $this->assertSame(1, \App\Models\FeedQueue::where('provider_id', $p->id)->count());

        $this->actingAs($user)->postJson("/providers/{$p->id}/toggle")->assertOk()->assertJson(['enabled' => false]);
        $this->assertSame(0, \App\Models\FeedQueue::where('provider_id', $p->id)->count());
    }

    public function test_worker_disables_provider_and_drops_job_at_error_threshold(): void
    {
        $user = $this->user();
        $p = \App\Models\Provider::create(['user_id' => $user->id, 'name' => 'X', 'type' => 'manual', 'enabled' => true]);
        $job = \App\Models\FeedQueue::enqueue($p);
        $job->forceFill(['error' => 4])->save(); // already past the threshold

        $this->artisan('feed:work', ['--once' => true])->assertSuccessful();

        $this->assertDatabaseMissing('feed_queue', ['id' => $job->id]); // job dropped
        $this->assertFalse((bool) $p->fresh()->enabled);                 // provider disabled
    }

    public function test_orphan_running_job_is_requeued_with_error_bump(): void
    {
        $user = $this->user();
        $p = \App\Models\Provider::create(['user_id' => $user->id, 'name' => 'X', 'type' => 'manual']);
        $job = \App\Models\FeedQueue::enqueue($p);
        $job->forceFill(['state' => 'running', 'dstart' => now()->subHours(2), 'error' => 0])->save();

        \App\Models\FeedQueue::reclaimOrphans();

        $fresh = $job->fresh();
        $this->assertSame('queued', $fresh->state);
        $this->assertSame(1, $fresh->error);
    }

    public function test_channel_browse_edit_delete_is_owner_scoped(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $p = \App\Models\Provider::create(['user_id' => $owner->id, 'name' => 'X', 'type' => 'm3u', 'url' => 'http://h/x.m3u']);

        // seed the per-provider store directly
        @unlink(\App\Services\ProviderStore::path($p->id));
        $store = new \App\Services\ProviderStore($p->id);
        $store->begin();
        $store->upsertChannel(['name' => 'Ch A', 'url' => 'http://h/a.ts', 'group' => 'News'], 'v1');
        $store->upsertChannel(['name' => 'Ch B', 'url' => 'http://h/b.ts', 'group' => 'News'], 'v1');
        $store->commit();
        $rows = $store->channels(10, 0);
        $cid  = $rows[0]['id'];

        // owner can browse (Tabulator shape), no user_id field present
        $body = $this->actingAs($owner)->getJson("/providers/{$p->id}/channels?page=1&size=50")->assertOk()->json();
        $this->assertSame(2, $body['total']);
        $this->assertArrayNotHasKey('user_id', $body['data'][0]);

        // owner can inline-edit an allowed field
        $this->actingAs($owner)->patchJson("/providers/{$p->id}/channels/{$cid}", ['field' => 'name', 'value' => 'Renamed'])
            ->assertOk()->assertJson(['ok' => true]);
        // disallowed field rejected
        $this->actingAs($owner)->patchJson("/providers/{$p->id}/channels/{$cid}", ['field' => 'id', 'value' => '9'])
            ->assertStatus(422);

        // not the owner -> 403 on browse, edit, delete
        $this->actingAs($other)->getJson("/providers/{$p->id}/channels")->assertForbidden();
        $this->actingAs($other)->patchJson("/providers/{$p->id}/channels/{$cid}", ['field' => 'name', 'value' => 'Hax'])->assertForbidden();
        $this->actingAs($other)->deleteJson("/providers/{$p->id}/channels/{$cid}")->assertForbidden();

        // owner deletes
        $this->actingAs($owner)->deleteJson("/providers/{$p->id}/channels/{$cid}")->assertOk();
        $this->assertSame(1, (new \App\Services\ProviderStore($p->id))->channelCount());

        @unlink(\App\Services\ProviderStore::path($p->id));
    }

    public function test_store_tracks_adds_and_updates_name_logo_in_place(): void
    {
        $pid = 99001;
        @unlink(\App\Services\ProviderStore::path($pid));

        // run 1: two channels
        $s1 = new \App\Services\ProviderStore($pid);
        $s1->begin();
        $s1->upsertChannel(['name' => 'CTV', 'url' => 'http://h/ctv.ts', 'group' => 'CA', 'tvg_logo' => 'old.png'], 'v1');
        $s1->upsertChannel(['name' => 'CBC', 'url' => 'http://h/cbc.ts', 'group' => 'CA'], 'v1');
        $s1->commit();
        $s1->sweep('v1');
        $this->assertSame(2, $s1->addedCount());

        // run 2: CTV renamed + new logo (same url), CBC unchanged, one brand-new channel
        $s2 = new \App\Services\ProviderStore($pid);
        $s2->begin();
        $s2->upsertChannel(['name' => 'CTV HD', 'url' => 'http://h/ctv.ts', 'group' => 'CA', 'tvg_logo' => 'new.png'], 'v2');
        $s2->upsertChannel(['name' => 'CBC', 'url' => 'http://h/cbc.ts', 'group' => 'CA'], 'v2');
        $s2->upsertChannel(['name' => 'TSN', 'url' => 'http://h/tsn.ts', 'group' => 'CA'], 'v2');
        $s2->commit();
        $removed = $s2->sweep('v2');

        $this->assertSame(1, $s2->addedCount(), 'only TSN is new');
        $this->assertSame(0, $removed);
        $this->assertSame(3, $s2->channelCount());
        // CTV updated in place — still one row, new name + logo, no duplicate
        $ctv = collect($s2->channels(50, 0))->firstWhere('url', 'http://h/ctv.ts');
        $this->assertSame('CTV HD', $ctv['name']);
        $this->assertSame('new.png', $ctv['tvg_logo']);

        @unlink(\App\Services\ProviderStore::path($pid));
    }

    public function test_manual_channel_survives_refresh_sweep(): void
    {
        $pid = 99002;
        @unlink(\App\Services\ProviderStore::path($pid));

        $s = new \App\Services\ProviderStore($pid);
        $s->begin();
        $s->upsertChannel(['name' => 'Src', 'url' => 'http://h/src.ts', 'group' => 'G'], 'v1');
        $s->commit();
        $manualId = $s->addChannel(['name' => 'My Channel', 'url' => 'http://h/manual.ts', 'group' => 'Mine']);
        $this->assertGreaterThan(0, $manualId);
        $this->assertSame(2, $s->channelCount());

        // four refreshes that DON'T include either url -> source row eventually swept, manual stays
        for ($i = 2; $i <= 6; $i++) {
            $r = new \App\Services\ProviderStore($pid);
            $r->begin();
            $r->upsertChannel(['name' => 'Other', 'url' => 'http://h/other.ts', 'group' => 'G'], "v{$i}");
            $r->commit();
            $r->sweep("v{$i}");
        }
        $final = new \App\Services\ProviderStore($pid);
        $urls  = array_column($final->channels(50, 0), 'url');
        $this->assertContains('http://h/manual.ts', $urls, 'manual entry must survive sweeps');
        $this->assertNotContains('http://h/src.ts', $urls, 'unseen source channel should be swept');

        @unlink(\App\Services\ProviderStore::path($pid));
    }

    public function test_add_channel_endpoint_is_owner_scoped(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $p = \App\Models\Provider::create(['user_id' => $owner->id, 'name' => 'P', 'type' => 'm3u', 'url' => 'http://h/p.m3u']);
        @unlink(\App\Services\ProviderStore::path($p->id));

        $this->actingAs($other)->postJson("/providers/{$p->id}/channels", ['name' => 'X', 'url' => 'http://h/x.ts'])->assertForbidden();
        $this->actingAs($owner)->postJson("/providers/{$p->id}/channels", ['name' => ''])->assertStatus(422);
        $this->actingAs($owner)->postJson("/providers/{$p->id}/channels", ['name' => 'My Ch', 'url' => 'http://h/my.ts'])
            ->assertOk()->assertJson(['ok' => true]);

        $rows = (new \App\Services\ProviderStore($p->id))->channels(10, 0);
        $this->assertSame('My Ch', $rows[0]['name']);
        $this->assertSame('user', $rows[0]['type']);

        @unlink(\App\Services\ProviderStore::path($p->id));
    }

    public function test_groups_endpoint_returns_counts_and_is_owner_scoped(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $p = \App\Models\Provider::create(['user_id' => $owner->id, 'name' => 'G', 'type' => 'm3u', 'url' => 'http://h/g.m3u']);
        @unlink(\App\Services\ProviderStore::path($p->id));

        $s = new \App\Services\ProviderStore($p->id);
        $s->begin();
        $s->upsertChannel(['name' => 'A', 'url' => 'http://h/a.ts', 'group' => 'News'], 'v1');
        $s->upsertChannel(['name' => 'B', 'url' => 'http://h/b.ts', 'group' => 'News'], 'v1');
        $s->upsertChannel(['name' => 'C', 'url' => 'http://h/c.ts', 'group' => 'Sports'], 'v1');
        $s->upsertGroup('News', 10, 'v1');
        $s->upsertGroup('Sports', 20, 'v1');
        $s->commit();

        $body = $this->actingAs($owner)->getJson("/providers/{$p->id}/groups")->assertOk()->json();
        $byTitle = collect($body['groups'])->keyBy('group_title');
        $this->assertSame(2, (int) $byTitle['News']['channels']);
        $this->assertSame(1, (int) $byTitle['Sports']['channels']);

        $this->actingAs($other)->getJson("/providers/{$p->id}/groups")->assertForbidden();

        @unlink(\App\Services\ProviderStore::path($p->id));
    }

    public function test_channels_endpoint_filters_by_exact_group(): void
    {
        $owner = $this->user();
        $p = \App\Models\Provider::create(['user_id' => $owner->id, 'name' => 'F', 'type' => 'm3u', 'url' => 'http://h/f.m3u']);
        @unlink(\App\Services\ProviderStore::path($p->id));
        $s = new \App\Services\ProviderStore($p->id);
        $s->begin();
        $s->upsertChannel(['name' => 'A', 'url' => 'http://h/a.ts', 'group' => 'News'], 'v1');
        $s->upsertChannel(['name' => 'B', 'url' => 'http://h/b.ts', 'group' => 'News'], 'v1');
        $s->upsertChannel(['name' => 'C', 'url' => 'http://h/c.ts', 'group' => 'Sports'], 'v1');
        $s->commit();

        $all = $this->actingAs($owner)->getJson("/providers/{$p->id}/channels")->json();
        $this->assertSame(3, $all['total']);

        $news = $this->actingAs($owner)->getJson("/providers/{$p->id}/channels?group=News")->json();
        $this->assertSame(2, $news['total']);
        $this->assertEqualsCanonicalizing(['A', 'B'], array_column($news['data'], 'name'));

        // group + search combine
        $both = $this->actingAs($owner)->getJson("/providers/{$p->id}/channels?group=News&search=A")->json();
        $this->assertSame(1, $both['total']);

        @unlink(\App\Services\ProviderStore::path($p->id));
    }

    public function test_add_group_endpoint_and_manual_group_survives_sweep(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $p = \App\Models\Provider::create(['user_id' => $owner->id, 'name' => 'GG', 'type' => 'm3u', 'url' => 'http://h/gg.m3u']);
        @unlink(\App\Services\ProviderStore::path($p->id));

        // seed a provider group via a run
        $s = new \App\Services\ProviderStore($p->id);
        $s->begin();
        $s->upsertChannel(['name' => 'A', 'url' => 'http://h/a.ts', 'group' => 'AutoGroup'], 'v1');
        $s->upsertGroup('AutoGroup', 10, 'v1');
        $s->commit();
        $s->sweep('v1');

        // not the owner -> forbidden
        $this->actingAs($other)->postJson("/providers/{$p->id}/groups", ['group_title' => 'Mine'])->assertForbidden();
        // owner adds a manual group
        $this->actingAs($owner)->postJson("/providers/{$p->id}/groups", ['group_title' => 'Mine'])->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($owner)->postJson("/providers/{$p->id}/groups", ['group_title' => ''])->assertStatus(422);

        $titles = array_column((new \App\Services\ProviderStore($p->id))->groups(), 'group_title');
        $this->assertContains('Mine', $titles);

        // refreshes that don't include AutoGroup or Mine: AutoGroup eventually swept, manual Mine stays
        for ($i = 2; $i <= 6; $i++) {
            $r = new \App\Services\ProviderStore($p->id);
            $r->begin();
            $r->upsertChannel(['name' => 'B', 'url' => 'http://h/b.ts', 'group' => 'Other'], "v{$i}");
            $r->upsertGroup('Other', 10, "v{$i}");
            $r->commit();
            $r->sweep("v{$i}");
        }
        $titles = array_column((new \App\Services\ProviderStore($p->id))->groups(), 'group_title');
        $this->assertContains('Mine', $titles, 'manual group must survive sweeps');
        $this->assertNotContains('AutoGroup', $titles, 'unseen provider group should be swept');

        @unlink(\App\Services\ProviderStore::path($p->id));
    }

    public function test_validator_pure_logic(): void
    {
        $this->assertTrue(ProviderValidator::contentMatchesType("#EXTM3U\n#EXTINF:-1,Foo\nhttp://x", 'm3u'));
        $this->assertFalse(ProviderValidator::contentMatchesType("<html>nope</html>", 'm3u'));
        $this->assertTrue(ProviderValidator::contentMatchesType('<?xml version="1.0"?><tv generator="x"></tv>', 'xmltv'));
        $this->assertFalse(ProviderValidator::contentMatchesType('<?xml version="1.0"?><rss></rss>', 'xmltv'));

        $xtreamJson = '{"user_info":{"auth":1,"status":"Active"},"server_info":{"timezone":"America/New_York"}}';
        $parsed = ProviderValidator::parseXtream($xtreamJson);
        $this->assertTrue($parsed['ok']);
        $this->assertSame('America/New_York', $parsed['timeshift']);
        $this->assertFalse(ProviderValidator::parseXtream('{"user_info":{"auth":0}}')['ok']);
    }
}
