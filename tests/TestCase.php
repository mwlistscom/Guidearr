<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // SAFETY NET: the per-playlist / per-provider SQLite stores resolve their location via
        // storage_path() at runtime, and several tests glob-unlink storage_path('app/{playlists,feeds}')
        // in their own setUp(). Relocate storage to a disposable temp dir so a test run can NEVER read,
        // write, or delete real playlist/provider data — even when the suite is (mistakenly) run inside a
        // live container where storage_path() points at bind-mounted production data.
        $base = sys_get_temp_dir().'/guidearr-testing-storage';
        foreach (['app/playlists', 'app/feeds', 'framework/cache/data', 'framework/views', 'framework/sessions', 'logs'] as $sub) {
            if (! is_dir("{$base}/{$sub}")) {
                @mkdir("{$base}/{$sub}", 0777, true);
            }
        }
        $this->app->useStoragePath($base);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
