<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The auth screens are the first thing a new user sees. They must carry the
 * operator's brand mark (Admin -> Branding), not the framework's stock logo.
 */
class AuthBrandingTest extends TestCase
{
    /** Public screens that render the shared auth layout. */
    private const SCREENS = ['/register', '/login', '/forgot-password'];

    public function test_auth_screens_show_the_branded_icon(): void
    {
        foreach (self::SCREENS as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee(route('branding.icon'), false);
        }
    }

    public function test_auth_screens_do_not_ship_the_stock_laravel_mark(): void
    {
        // A fragment of the bundled Laravel logo path data. If this reappears, the
        // brand mark has been reverted to the framework default.
        foreach (self::SCREENS as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee('M17.2 5.633 8.6.855 0 5.633v26.51', false);
        }
    }
}
