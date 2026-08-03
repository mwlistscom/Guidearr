<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ImageDownscaler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        // Derived from the shipped assets rather than hardcoded, so replacing a default
        // image does not falsely fail this.
        $response = $this->actingAs($this->admin())->get(route('admin.branding'))->assertOk();

        foreach (['icon-default.png', 'logo-default.png'] as $file) {
            [$w, $h] = getimagesize(public_path('branding/'.$file));
            $response->assertSee("{$w} × {$h}", false);
        }
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

    public function test_an_oversized_upload_is_downscaled_on_the_way_in(): void
    {
        if (! ImageDownscaler::available()) {
            $this->markTestSkipped('gd is not installed in this environment');
        }

        // A full-resolution export, the kind an operator actually uploads.
        // (1600px rather than 3000px so the test itself stays inside PHP's memory limit.)
        $src = sys_get_temp_dir().'/guidearr-upload-'.getmypid().'.png';
        $im = imagecreatetruecolor(1600, 1600);
        imagefilledrectangle($im, 0, 0, 1599, 1599, imagecolorallocate($im, 20, 120, 220));
        imagepng($im, $src);
        imagedestroy($im);

        $this->actingAs($this->admin())
            ->post(route('admin.branding.update', 'icon'), [
                'icon' => new UploadedFile($src, 'icon.png', 'image/png', null, true),
            ])
            ->assertRedirect();

        $stored = glob(storage_path('app/branding').'/icon.*')[0] ?? null;
        $this->assertNotNull($stored, 'the upload should have been stored');

        [$w, $h] = getimagesize($stored);
        $this->assertSame(512, $w, 'icon should be capped at its 512px maxEdge');
        $this->assertSame(512, $h);

        @unlink($src);
        @unlink($stored);
    }

    public function test_a_reasonably_sized_upload_is_stored_untouched(): void
    {
        if (! ImageDownscaler::available()) {
            $this->markTestSkipped('gd is not installed in this environment');
        }

        $src = sys_get_temp_dir().'/guidearr-small-'.getmypid().'.png';
        $im = imagecreatetruecolor(256, 256);
        imagepng($im, $src);
        imagedestroy($im);
        $before = md5_file($src);

        $this->actingAs($this->admin())
            ->post(route('admin.branding.update', 'icon'), [
                'icon' => new UploadedFile($src, 'icon.png', 'image/png', null, true),
            ])
            ->assertRedirect();

        $stored = glob(storage_path('app/branding').'/icon.*')[0] ?? null;
        $this->assertSame($before, md5_file($stored), 'an in-spec upload must be byte-identical');

        @unlink($stored);
    }
}
