<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Neither brand asset is resized on the server, so the admin page has to tell an
 * operator what size to upload — and show them when what they uploaded is far
 * bigger than anything it is drawn at.
 */
class BrandingGuidanceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    public function test_page_states_a_recommended_size_for_each_asset(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.branding'))
            ->assertOk()
            ->assertSee('256 × 256', false)   // icon
            ->assertSee('600 × 300', false)   // logo
            ->assertSee('Recommended:', false);
    }

    public function test_page_reports_the_current_asset_dimensions_and_weight(): void
    {
        // The bundled defaults are 512x512 (icon) and 512x279 (logo).
        $this->actingAs($this->admin())
            ->get(route('admin.branding'))
            ->assertOk()
            ->assertSee('512 × 512', false)
            ->assertSee('512 × 279', false);
    }

    public function test_page_explains_that_assets_are_not_resized_server_side(): void
    {
        // Without this, "up to 10 MB" reads as an invitation to upload 10 MB.
        $this->actingAs($this->admin())
            ->get(route('admin.branding'))
            ->assertOk()
            ->assertSee('resized on the server', false);
    }

    public function test_defaults_are_within_guidance_and_raise_no_warning(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.branding'))
            ->assertOk()
            ->assertDontSee('larger than it needs to be', false);
    }
}
