<?php

namespace Tests\Feature;

use App\Models\FeedQueue;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private function writeBeat(int $ageSeconds): void
    {
        $dir = storage_path('app/health');
        if (! is_dir($dir)) { mkdir($dir, 0775, true); }
        file_put_contents($dir . '/worker.beat', (string) (time() - $ageSeconds));
    }

    private function clearBeat(): void
    {
        @unlink(storage_path('app/health/worker.beat'));
    }

    public function test_ok_when_worker_fresh_db_up_and_no_stuck_jobs(): void
    {
        $this->writeBeat(5);
        $this->artisan('health:check')->assertExitCode(0);
        $this->clearBeat();
    }

    public function test_flags_missing_or_stale_worker(): void
    {
        $this->clearBeat();
        $this->artisan('health:check')->assertExitCode(1);   // missing

        $this->writeBeat(99999);
        $this->artisan('health:check')->assertExitCode(1);   // stale
        $this->clearBeat();
    }

    public function test_flags_stuck_running_job(): void
    {
        $this->writeBeat(5);

        $user = \App\Models\User::factory()->create();
        $provider = Provider::create(['user_id' => $user->id, 'name' => 'StuckCo', 'type' => 'm3u']);

        FeedQueue::create([
            'msgid'       => 'stucktest01',
            'user_id'     => $user->id,
            'provider_id' => $provider->id,
            'type'        => 'm3u',
            'state'       => 'running',
            'dstart'      => now()->subHours(3),   // well past the 60m orphan window
        ]);

        $this->artisan('health:check --format=env')
            ->expectsOutputToContain('queue=stuck')
            ->assertExitCode(1);

        $this->clearBeat();
    }

    public function test_flags_provider_that_never_refreshed(): void
    {
        $this->writeBeat(5);

        $user = \App\Models\User::factory()->create();
        // An enabled provider with a null last_refresh_at should read as stale.
        Provider::create([
            'user_id'         => $user->id,
            'name'            => 'NeverCo',
            'type'            => 'm3u',
            'enabled'         => true,
            'last_refresh_at' => null,
        ]);

        $this->artisan('health:check --format=env')
            ->expectsOutputToContain('refresh=stale')
            ->assertExitCode(1);

        $this->clearBeat();
    }
}
