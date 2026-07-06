<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Build the application with storage pointed at a disposable temp dir *before* it boots, so no
     * test can ever read, write, or delete real playlist / provider / settings data — even when the
     * suite is (mistakenly) run inside a live container where storage_path() points at bind-mounted
     * production data. Relocating before boot also means boot-time code (e.g. SocialConfig hydration
     * in AppServiceProvider) reads the isolated store, and each test starts from a clean settings store.
     */
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        // Mirror the framework's own createApplication bookkeeping (used by trait setup/teardown).
        $this->traitsUsedByTest = class_uses_recursive(static::class);

        $base = sys_get_temp_dir().'/guidearr-testing-storage';
        foreach (['app/playlists', 'app/feeds', 'app/settings', 'framework/cache/data', 'framework/views', 'framework/sessions', 'logs'] as $sub) {
            if (! is_dir("{$base}/{$sub}")) {
                @mkdir("{$base}/{$sub}", 0777, true);
            }
        }
        $store = "{$base}/app/settings/app.json"; // fresh settings store per test
        if (is_file($store)) {
            @unlink($store);
        }
        $app->useStoragePath($base);

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
