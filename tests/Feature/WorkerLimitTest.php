<?php

namespace Tests\Feature;

use App\Console\Commands\FeedSupervise;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerLimitTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    public function test_slots_to_spawn_fills_free_slots_but_never_exceeds_queued_or_limit(): void
    {
        // Free slots (limit - active), capped by queued jobs.
        $this->assertSame(3, FeedSupervise::slotsToSpawn(4, 1, 10)); // 3 free slots, plenty queued
        $this->assertSame(1, FeedSupervise::slotsToSpawn(4, 0, 1));  // only 1 job queued
        $this->assertSame(0, FeedSupervise::slotsToSpawn(4, 4, 10)); // already at the limit
        $this->assertSame(0, FeedSupervise::slotsToSpawn(4, 2, 0));  // nothing queued
        $this->assertSame(1, FeedSupervise::slotsToSpawn(1, 0, 5));  // limit 1 never spawns a second
        $this->assertSame(0, FeedSupervise::slotsToSpawn(1, 1, 5));  // one already running under limit 1
    }

    public function test_worker_limit_defaults_to_one_and_is_clamped(): void
    {
        $this->assertSame(1, Settings::workerLimit()); // default

        Settings::set('worker_limit', 4);
        $this->assertSame(4, Settings::workerLimit());

        Settings::set('worker_limit', 0);   // below floor
        $this->assertSame(1, Settings::workerLimit());

        Settings::set('worker_limit', 999); // above ceiling
        $this->assertSame(16, Settings::workerLimit());
    }

    public function test_config_page_shows_the_worker_limit_field(): void
    {
        Settings::set('worker_limit', 3);

        $this->actingAs($this->admin())
            ->get(route('admin.config'))
            ->assertOk()
            ->assertSee('Worker limit', false)
            ->assertSee('name="worker_limit"', false);
    }

    public function test_admin_can_save_the_worker_limit(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), [
                'links_base_url' => '',
                'serve_max_ips' => 10,
                'serve_window_hours' => 4,
                'worker_limit' => 6,
            ])
            ->assertRedirect(route('admin.config'));

        $this->assertSame(6, Settings::workerLimit());
    }

    public function test_worker_limit_is_validated(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), [
                'links_base_url' => '',
                'serve_max_ips' => 10,
                'serve_window_hours' => 4,
                'worker_limit' => 99, // over max:16
            ])
            ->assertSessionHasErrors('worker_limit');
    }
}
