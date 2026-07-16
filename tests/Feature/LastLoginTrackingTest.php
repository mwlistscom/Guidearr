<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LastLoginTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_event_stamps_last_login_at(): void
    {
        $user = User::factory()->create(['last_login_at' => null]);
        $this->assertNull($user->last_login_at);

        event(new Login('web', $user, false));

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_new_registration_has_no_last_login_until_first_login(): void
    {
        $user = User::factory()->create(['last_login_at' => null]);

        // created_at exists (date registered) but last_login_at stays null pre-login.
        $this->assertNotNull($user->created_at);
        $this->assertNull($user->fresh()->last_login_at);
    }
}
