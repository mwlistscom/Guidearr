<?php

namespace Tests\Feature;

use App\Support\MaintenanceTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CachePruneExpiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('cache.default', 'database');
        Config::set('cache.stores.database.driver', 'database');
        Config::set('cache.stores.database.table', 'cache');
        Config::set('cache.stores.database.lock_table', 'cache_locks');
        Config::set('cache.stores.database.connection', null);
    }

    private function row(string $key, int $expiration): void
    {
        DB::table('cache')->insert(['key' => $key, 'value' => 's:1:"x";', 'expiration' => $expiration]);
    }

    public function test_it_deletes_expired_rows_and_keeps_live_ones(): void
    {
        $now = now()->getTimestamp();
        $this->row('expired-long-ago', $now - 86400 * 80);
        $this->row('expired-just-now', $now - 1);
        $this->row('live-for-an-hour', $now + 3600);
        $this->row('live-for-a-day', $now + 86400);
        DB::table('cache_locks')->insert([
            ['key' => 'lock-dead', 'owner' => 'a', 'expiration' => $now - 10],
            ['key' => 'lock-live', 'owner' => 'b', 'expiration' => $now + 600],
        ]);

        $exit = Artisan::call('cache:prune-expired');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('removed 2 expired cache row(s) and 1 expired lock(s); 2 live row(s) remain', Artisan::output());
        $this->assertSame(['live-for-a-day', 'live-for-an-hour'], DB::table('cache')->orderBy('key')->pluck('key')->all());
        $this->assertSame(['lock-live'], DB::table('cache_locks')->pluck('key')->all());
    }

    public function test_it_uses_application_time_so_a_travelled_clock_is_honoured(): void
    {
        $now = now()->getTimestamp();
        $this->row('expires-in-an-hour', $now + 3600);

        $this->travel(2)->hours();
        Artisan::call('cache:prune-expired');

        $this->assertSame(0, DB::table('cache')->count());
    }

    public function test_it_does_nothing_on_a_non_database_store(): void
    {
        $now = now()->getTimestamp();
        $this->row('expired', $now - 10);
        Config::set('cache.default', 'array');

        $exit = Artisan::call('cache:prune-expired');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('not database', Artisan::output());
        $this->assertSame(1, DB::table('cache')->count(), 'a non-database default store must leave the table alone');
    }

    public function test_it_is_a_safe_maintenance_task_and_runs_through_maintenance_run(): void
    {
        $this->assertArrayHasKey('cache-prune', MaintenanceTasks::safe());
        $this->assertArrayNotHasKey('cache-prune', MaintenanceTasks::destructive());
        $this->assertSame('cache:prune-expired', MaintenanceTasks::all()['cache-prune']['command']);

        $this->row('expired', now()->getTimestamp() - 10);
        $exit = Artisan::call('maintenance:run', ['task' => 'cache-prune', '--token' => 'test']);

        $this->assertSame(0, $exit);
        $this->assertSame(0, DB::table('cache')->count());
    }

    public function test_it_is_scheduled_daily(): void
    {
        $src = file_get_contents(base_path('routes/console.php'));
        $this->assertMatchesRegularExpression("/Schedule::command\\('maintenance:run cache-prune'\\)\\s*->daily\\(\\)/", $src);
    }
}
