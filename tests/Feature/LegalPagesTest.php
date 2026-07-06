<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LegalDocs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(storage_path('app/settings/app.json'));
    }

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    public function test_public_pages_render_shipped_defaults(): void
    {
        $this->get('/privacy')->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('Signing in with Google or Facebook');
        $this->get('/terms')->assertOk()->assertSee('Terms of Service');
        $this->get('/cookies')->assertOk()->assertSee('Cookie Policy');
        $this->get('/data-deletion')->assertOk()
            ->assertSee('Data Deletion')
            ->assertSee('Delete your entire account');
    }

    public function test_known_slugs_only(): void
    {
        $this->assertTrue(LegalDocs::exists('privacy'));
        $this->assertFalse(LegalDocs::exists('bogus'));
    }

    public function test_markdown_renders_to_html(): void
    {
        $html = LegalDocs::html('cookies');
        $this->assertStringContainsString('<h1>', $html);
        $this->assertStringContainsString('<table>', $html); // GFM table in the cookie policy
    }

    public function test_admin_can_edit_and_public_reflects_it(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.legal.update'), ['privacy' => "# Custom Privacy\n\nHello **there**."])
            ->assertRedirect(route('admin.legal'));

        $this->assertTrue(LegalDocs::isCustom('privacy'));
        $this->get('/privacy')->assertOk()->assertSee('Custom Privacy')->assertSee('Hello');
        // editing only privacy must not touch the others
        $this->assertFalse(LegalDocs::isCustom('terms'));
        $this->assertFalse(LegalDocs::isCustom('cookies'));
    }

    public function test_saving_the_default_text_keeps_it_tracking_default(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.legal.update'), ['terms' => LegalDocs::default('terms')]);
        $this->assertFalse(LegalDocs::isCustom('terms')); // identical to default => not marked custom
    }

    public function test_reset_restores_default(): void
    {
        LegalDocs::save('cookies', '# Mine only');
        $this->assertTrue(LegalDocs::isCustom('cookies'));

        $this->actingAs($this->admin())
            ->delete(route('admin.legal.reset', 'cookies'))
            ->assertRedirect(route('admin.legal'));

        $this->assertFalse(LegalDocs::isCustom('cookies'));
        $this->get('/cookies')->assertSee('Cookie Policy');
    }

    public function test_raw_html_is_stripped_from_output(): void
    {
        LegalDocs::save('terms', 'Hi <script>alert(1)</script> there');
        $this->assertStringNotContainsString('<script>', LegalDocs::html('terms'));
    }

    public function test_editor_requires_admin(): void
    {
        $this->get(route('admin.legal'))->assertRedirect(); // gated by admin middleware
    }
}
