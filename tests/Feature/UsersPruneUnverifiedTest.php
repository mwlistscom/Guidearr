<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UsersPruneUnverifiedTest extends TestCase
{
    use RefreshDatabase;

    /** Create a user with a specific registration age (created_at is forced via the DB to bypass auto-timestamps). */
    private function user(array $attrs, int $registeredDaysAgo): User
    {
        $u = User::factory()->create($attrs);
        DB::table('users')->where('id', $u->id)->update(['created_at' => now()->subDays($registeredDaysAgo)]);

        return $u->fresh();
    }

    public function test_deletes_unverified_account_older_than_14_days(): void
    {
        $u = $this->user(['email_verified_at' => null, 'is_admin' => false], 20);

        Artisan::call('users:prune-unverified');

        $this->assertDatabaseMissing('users', ['id' => $u->id]);
    }

    public function test_keeps_recently_registered_unverified_account(): void
    {
        $u = $this->user(['email_verified_at' => null, 'is_admin' => false], 5);

        Artisan::call('users:prune-unverified');

        $this->assertDatabaseHas('users', ['id' => $u->id]);
    }

    public function test_keeps_verified_account_however_old(): void
    {
        $u = $this->user(['email_verified_at' => now(), 'is_admin' => false], 90);

        Artisan::call('users:prune-unverified');

        $this->assertDatabaseHas('users', ['id' => $u->id]);
    }

    public function test_protects_admins_even_when_unverified_and_old(): void
    {
        $u = $this->user(['email_verified_at' => null, 'is_admin' => true], 90);

        Artisan::call('users:prune-unverified');

        $this->assertDatabaseHas('users', ['id' => $u->id]);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $u = $this->user(['email_verified_at' => null, 'is_admin' => false], 20);

        Artisan::call('users:prune-unverified', ['--dry-run' => true]);

        $this->assertDatabaseHas('users', ['id' => $u->id]);
    }

    public function test_deletion_cascades_providers_and_queues_store_cleanup(): void
    {
        $u = $this->user(['email_verified_at' => null, 'is_admin' => false], 20);
        $p = Provider::create([
            'user_id' => $u->id, 'name' => 'P', 'type' => 'm3u', 'url' => 'http://h/l.m3u',
            'enabled' => true, 'refresh_hour' => 2,
        ]);

        Artisan::call('users:prune-unverified');

        $this->assertDatabaseMissing('users', ['id' => $u->id]);
        $this->assertDatabaseMissing('providers', ['id' => $p->id]);   // FK cascade removed the provider
        $this->assertDatabaseHas('purge_queue', ['user_id' => $u->id]); // deleting hook queued store cleanup
    }
}
