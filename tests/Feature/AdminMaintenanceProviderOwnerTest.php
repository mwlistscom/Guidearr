<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMaintenanceProviderOwnerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active'])->save();

        return $u;
    }

    public function test_provider_activity_shows_owner_name_and_user_id(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['name' => 'Provider Owner']);
        $provider = Provider::create([
            'user_id' => $owner->id, 'name' => 'Owned Provider', 'type' => 'm3u',
            'url' => 'http://h/list.m3u', 'enabled' => true, 'refresh_hour' => 2,
        ]);

        $res = $this->actingAs($admin)->get(route('admin.maintenance'));

        $res->assertOk()
            ->assertSee('Owner')
            ->assertSee('Provider Owner')            // owner name in the row
            ->assertSee((string) $owner->id)         // owner user id in the row
            ->assertSee('Owned Provider');
    }
}
