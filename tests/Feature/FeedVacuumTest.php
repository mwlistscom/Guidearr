<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use App\Services\ProviderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PDO;
use Tests\TestCase;

class FeedVacuumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(storage_path('app/feeds/*.sqlite')) ?: [] as $f) {
            @unlink($f);
        }
    }

    public function test_feed_vacuum_shrinks_a_bloated_store_and_preserves_data(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $p = Provider::create(['user_id' => $u->id, 'name' => 'Big', 'type' => 'xtream', 'url' => 'http://h', 'enabled' => true, 'refresh_hour' => 2]);

        $s = new ProviderStore($p->id);
        $s->begin();
        for ($i = 0; $i < 2000; $i++) {
            $s->upsertChannel(['name' => "C{$i}", 'url' => "http://h/{$i}.ts", 'group' => 'G'], 'v1');
        }
        $s->commit();

        // Create free-page bloat: delete 90% of the rows directly (mimics mark-sweep churn).
        $path = ProviderStore::path($p->id);
        $raw = new PDO('sqlite:'.$path);
        $raw->exec('DELETE FROM channels WHERE id % 10 != 0');
        $raw = null;
        clearstatcache(true, $path);
        $bloated = filesize($path);
        $kept = (new ProviderStore($p->id))->counts()['channels'];

        $this->assertSame(0, Artisan::call('feed:vacuum'));

        clearstatcache(true, $path);
        $this->assertLessThan($bloated, filesize($path), 'VACUUM should shrink the bloated file');
        $this->assertGreaterThan(0, $kept);
        $this->assertSame($kept, (new ProviderStore($p->id))->counts()['channels'], 'data must be preserved');
    }
}
