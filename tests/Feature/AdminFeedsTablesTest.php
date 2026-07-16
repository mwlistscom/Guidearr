<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFeedsTablesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active'])->save();

        return $u;
    }

    public function test_feeds_page_renders_paginated_grids_with_user_id(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create(['name' => 'Zed Example', 'email' => 'zed@example.com']);

        $res = $this->actingAs($admin)->get(route('admin.feeds'));

        $res->assertOk()
            ->assertSee('users-grid')                  // Users converted to a grid
            ->assertSee('paginationSize: 25', false)   // both grids paginate at 25
            ->assertSee("'id'", false)                 // ID column present in the grid config
            ->assertSee('zed@example.com')             // member data serialized into the grid JSON
            ->assertSee('"id":'.$member->id, false);   // ...with its user id
    }
}
