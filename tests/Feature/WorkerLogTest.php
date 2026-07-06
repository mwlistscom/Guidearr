<?php

namespace Tests\Feature;

use App\Models\FeedQueue;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_log_channel_targets_its_own_file(): void
    {
        $this->assertSame('single', config('logging.channels.worker.driver'));
        $this->assertSame(storage_path('logs/worker.log'), config('logging.channels.worker.path'));
    }

    public function test_successful_job_is_summarised_in_worker_log(): void
    {
        $log = storage_path('logs/worker.log');
        @unlink($log);

        $user = User::factory()->create();
        $provider = Provider::create(['user_id' => $user->id, 'name' => 'Loggy', 'type' => 'manual']);
        FeedQueue::enqueue($provider);

        $this->artisan('feed:work', ['--once' => true])->assertSuccessful();

        $this->assertFileExists($log);
        $contents = (string) file_get_contents($log);
        $this->assertStringContainsString("#{$provider->id} manual 'Loggy' — claimed", $contents);
        $this->assertStringContainsString("#{$provider->id} — done", $contents);
    }

    public function test_failed_job_is_recorded_in_worker_log(): void
    {
        $log = storage_path('logs/worker.log');
        @unlink($log);

        $user = User::factory()->create();
        // An m3u provider with no URL fails fast ("No URL set.") without any network.
        $provider = Provider::create(['user_id' => $user->id, 'name' => 'Broken', 'type' => 'm3u']);
        FeedQueue::enqueue($provider);

        $this->artisan('feed:work', ['--once' => true])->assertSuccessful();

        $this->assertStringContainsString("'Broken' — failed", (string) file_get_contents($log));
    }
}
