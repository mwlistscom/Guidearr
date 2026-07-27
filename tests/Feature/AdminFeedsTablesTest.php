<?php

namespace Tests\Feature;

use App\Models\FeedQueue;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFeedsTablesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active'])->save();

        return $u;
    }

    public function test_feeds_page_renders_paginated_grids_with_user_id(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['name' => 'Zed Example', 'email' => 'zed@example.com']);

        $res = $this->actingAs($admin)->get(route('admin.feeds'));

        $res->assertOk()
            ->assertSee('users-grid')                  // Users converted to a grid
            ->assertSee('paginationSize: 25', false)   // both grids paginate at 25
            ->assertSee("'id'", false)                 // ID column present in the grid config
            ->assertSee('Last touch')                  // last-touch column added to the users grid
            ->assertSee('del-modal')                   // proper delete dialog shipped
            ->assertSee('zed@example.com')             // member data serialized into the grid JSON
            ->assertSee('"id":'.$member->id, false);   // ...with its user id
    }

    public function test_feeds_users_grid_flags_self_and_admin_rows(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Member']);

        $res = $this->actingAs($admin)->get(route('admin.feeds'));

        // The grid JSON carries flags the UI uses to hide the self-delete button and warn on admins.
        $res->assertOk()
            ->assertSee('"is_self":true', false)   // the admin's own row
            ->assertSee('"is_admin":true', false); // ...which is also an admin
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->deleteJson(route('admin.users.destroy', $admin))
            ->assertStatus(422);

        $this->assertNotNull($admin->fresh(), 'admin must not be able to delete themselves');
    }

    public function test_disabled_cold_provider_is_flagged_in_queue_json(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $provider = Provider::create([
            'user_id' => $owner->id, 'name' => 'Cold One', 'type' => 'm3u', 'url' => 'http://h/l.m3u',
            'enabled' => false, 'last_status' => Provider::REAPED_STATUS, 'refresh_hour' => 2,
        ]);
        FeedQueue::enqueue($provider);

        $this->actingAs($admin)->get(route('admin.feeds'))
            ->assertOk()
            ->assertSee('"disabled":true', false)
            ->assertSee('"cold":true', false)
            ->assertSee('q-cold');   // the COLD badge styling ships
    }

    public function test_queue_log_returns_provider_log_lines(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $provider = Provider::create([
            'user_id' => $owner->id, 'name' => 'Logged', 'type' => 'm3u', 'url' => 'http://h/l.m3u',
            'enabled' => true, 'refresh_hour' => 2,
        ]);
        FeedQueue::enqueue($provider);
        $job = FeedQueue::where('provider_id', $provider->id)->firstOrFail();
        \App\Models\FeedLog::write('msg-abc', $provider->id, $owner->id, 'info', 'Downloaded 42 channels');

        $this->actingAs($admin)->getJson(route('admin.feeds.queue.log', $job))
            ->assertOk()
            ->assertJsonFragment(['message' => 'Downloaded 42 channels']);
    }

    public function test_queue_run_reenables_and_enqueues_provider(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        $provider = Provider::create([
            'user_id' => $owner->id, 'name' => 'Cold Two', 'type' => 'm3u', 'url' => 'http://h/l.m3u',
            'enabled' => false, 'last_status' => Provider::REAPED_STATUS, 'refresh_hour' => 2,
        ]);
        FeedQueue::enqueue($provider);
        $job = FeedQueue::where('provider_id', $provider->id)->firstOrFail();
        $job->update(['state' => 'done']);

        $this->actingAs($admin)->postJson(route('admin.feeds.queue.run', $job))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertTrue((bool) $provider->fresh()->enabled, 'run re-enables a disabled provider');
        $this->assertNotNull($provider->fresh()->last_touch_at, 'run stamps activity so the reaper skips it');
        $this->assertSame('queued', $job->fresh()->state, 'run re-queues the job');
    }

    public function test_job_queue_shows_user_number_next_start_and_edit_modal(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['name' => 'Queue Owner']);
        $provider = Provider::create([
            'user_id' => $owner->id, 'name' => 'Queued Provider', 'type' => 'm3u',
            'url' => 'http://h/list.m3u', 'enabled' => true, 'refresh_hour' => 2, 'refresh_minute' => 30,
        ]);
        FeedQueue::enqueue($provider);

        $res = $this->actingAs($admin)->get(route('admin.feeds'));

        $res->assertOk()
            ->assertSee('User #')                        // user-number column header
            ->assertSee('Next start')                    // computed next-refresh column
            ->assertSee('jq-modal')                      // edit modal shipped
            ->assertSee('"user_id":'.$owner->id, false); // owner id serialized into the queue JSON
    }
}
