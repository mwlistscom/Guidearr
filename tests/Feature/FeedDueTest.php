<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FeedDueTest extends TestCase
{
    use RefreshDatabase;

    private function provider(User $u, int $hour, int $minute, $lastRefresh = null, bool $enabled = true): Provider
    {
        return Provider::create([
            'user_id' => $u->id, 'name' => 'P', 'type' => 'm3u', 'url' => 'http://h/list.m3u',
            'enabled' => $enabled, 'refresh_hour' => $hour, 'refresh_minute' => $minute,
            'last_refresh_at' => $lastRefresh,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_enqueues_provider_when_due(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));
        $p = $this->provider(User::factory()->create(), 7, 0, null);

        $this->artisan('feed:due')->assertSuccessful();

        $this->assertDatabaseHas('feed_queue', ['provider_id' => $p->id, 'state' => 'queued']);
    }

    public function test_skips_provider_not_yet_due(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 6, 0, 0)); // before 07:00
        $p = $this->provider(User::factory()->create(), 7, 0, null);

        $this->artisan('feed:due')->assertSuccessful();

        $this->assertDatabaseMissing('feed_queue', ['provider_id' => $p->id]);
    }

    public function test_skips_provider_already_refreshed_today(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 30, 0));
        // refreshed at 07:05 today, after the 07:00 scheduled time
        $p = $this->provider(User::factory()->create(), 7, 0, Carbon::create(2026, 6, 5, 7, 5, 0));

        $this->artisan('feed:due')->assertSuccessful();

        $this->assertDatabaseMissing('feed_queue', ['provider_id' => $p->id]);
    }

    public function test_does_not_stomp_an_in_flight_job(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));
        $p = $this->provider(User::factory()->create(), 7, 0, null);

        // a job is already running for this provider
        \App\Models\FeedQueue::enqueue($p);
        \App\Models\FeedQueue::where('provider_id', $p->id)->update(['state' => 'running']);

        $this->artisan('feed:due')->assertSuccessful();

        // still exactly one row, still running (not reset to queued)
        $this->assertSame(1, \App\Models\FeedQueue::where('provider_id', $p->id)->count());
        $this->assertDatabaseHas('feed_queue', ['provider_id' => $p->id, 'state' => 'running']);
    }

    public function test_skips_disabled_provider(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 7, 0, 0));
        $p = $this->provider(User::factory()->create(), 7, 0, null, enabled: false);

        $this->artisan('feed:due')->assertSuccessful();

        $this->assertDatabaseMissing('feed_queue', ['provider_id' => $p->id]);
    }
}
