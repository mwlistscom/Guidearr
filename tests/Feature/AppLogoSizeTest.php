<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The brand mark in the app chrome must be sized by a utility that is actually in the
 * compiled stylesheet.
 *
 * public/build is gitignored and the documented upgrade path never runs `npm run build`,
 * so an upgraded install keeps the CSS it already had. A size utility it has never
 * compiled would silently do nothing, leaving the image unconstrained inside the sidebar.
 */
class AppLogoSizeTest extends TestCase
{
    use RefreshDatabase;

    /** The component's markup with Blade comments stripped — comments mention other sizes. */
    private function markup(): string
    {
        $blade = (string) file_get_contents(resource_path('views/components/app-logo.blade.php'));

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade);
    }

    private function compiledCss(): ?string
    {
        $files = glob(public_path('build/assets/app-*.css')) ?: [];

        return $files ? (string) file_get_contents($files[0]) : null;
    }

    public function test_the_mark_is_larger_than_the_old_32px(): void
    {
        $markup = $this->markup();

        $this->assertStringContainsString('size-10', $markup);
        $this->assertStringNotContainsString('size-8', $markup, 'the 32px mark was too small to read');
    }

    public function test_every_size_utility_used_exists_in_the_compiled_css(): void
    {
        $css = $this->compiledCss();

        if ($css === null) {
            $this->markTestSkipped('no compiled stylesheet in this environment');
        }

        preg_match_all('/\bsize-(\d+)\b/', $this->markup(), $m);

        $this->assertNotEmpty($m[1], 'the component should size the mark explicitly');

        foreach (array_unique($m[1]) as $n) {
            $this->assertStringContainsString(
                ".size-{$n}",
                $css,
                "size-{$n} is used but not in the compiled CSS — it would have no effect on an ".
                'install that upgraded without rebuilding assets',
            );
        }
    }

    public function test_the_dashboard_renders_the_mark(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(route('branding.icon'), false)
            ->assertSee('size-10', false);
    }
}
