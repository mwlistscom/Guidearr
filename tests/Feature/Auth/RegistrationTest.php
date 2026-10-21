<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        // Registration signs nobody in (see RegistrationEnumerationTest): the account is
        // created and waits for its emailed code.
        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();
        $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'email_verified_at' => null]);
    }
}
