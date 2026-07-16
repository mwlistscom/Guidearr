<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminUsersTableTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active'])->save();

        return $u;
    }

    public function test_index_shows_registered_and_last_login_columns(): void
    {
        $admin = $this->admin();
        $seen = User::factory()->create(['last_login_at' => now()->subDays(2)]);
        $never = User::factory()->create(['last_login_at' => null]);

        $res = $this->actingAs($admin)->get(route('admin.users'));

        $res->assertOk()
            ->assertSee('Registered')
            ->assertSee('Last login')
            ->assertSee($seen->last_login_at->format('Y-m-d H:i'))
            ->assertSee('never')                 // the null-last-login user
            ->assertSee('users-pager')           // pager shipped
            ->assertSee('PAGE_SIZE = 25', false); // 25 per page
    }

    public function test_online_users_are_derived_from_the_session_store(): void
    {
        $admin = $this->admin();
        $online = User::factory()->create();
        $offline = User::factory()->create();

        // A live DB session for $online, and a long-expired one for $offline.
        DB::table('sessions')->insert([
            ['id' => 'sess-online', 'user_id' => $online->id, 'ip_address' => '127.0.0.1',
                'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'sess-stale', 'user_id' => $offline->id, 'ip_address' => '127.0.0.1',
                'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->subHours(3)->timestamp],
        ]);

        $res = $this->actingAs($admin)->get(route('admin.users'));

        $res->assertOk()
            ->assertSee('online-dot on', false)   // the online user rendered a lit dot
            ->assertSeeInOrder([                   // that lit dot belongs to $online's row
                'data-id="'.$online->id.'"',
                'online-dot on',
            ], false);
    }
}
