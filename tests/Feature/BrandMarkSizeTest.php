<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The brand mark appears in four independent layouts, each with its own styling: the app
 * chrome (Tailwind), the admin sidebar, and the standalone legal/license/docs pages
 * (plain inline CSS). Enlarging one of them fixes only that one — which is exactly what
 * happened when the app chrome was raised to 40px and the admin sidebar stayed at 30px.
 *
 * These assert a floor on every layout so they cannot drift apart again unnoticed.
 */
class BrandMarkSizeTest extends TestCase
{
    use RefreshDatabase;

    /** App chrome (dashboard + admin sidebar) — these two must match exactly. */
    private const CHROME = 56;

    /** Standalone public pages (legal/license/docs) carry a smaller header mark. */
    private const PAGE = 40;

    /** The admin stylesheet with Blade expressions neutralised — `}}` ends a CSS-rule regex early. */
    private function adminCss(): string
    {
        $css = (string) file_get_contents(resource_path('views/admin/layout.blade.php'));

        return (string) preg_replace('/\{\{.*?\}\}/s', 'BLADE', $css);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    public function test_the_admin_sidebar_mark_is_legible(): void
    {
        $layout = $this->adminCss();

        preg_match('/\.sidebar \.brand \.logo \{[^}]*width:\s*(\d+)px/s', $layout, $m);

        $this->assertNotEmpty($m, 'could not find the admin sidebar brand mark rule');
        $this->assertGreaterThanOrEqual(
            self::CHROME,
            (int) $m[1],
            'the admin sidebar mark is smaller than every other layout',
        );
    }

    public function test_the_standalone_pages_match(): void
    {
        foreach (['legal', 'license', 'docs'] as $page) {
            $src = file_get_contents(resource_path("views/{$page}.blade.php"));

            if (! preg_match('/\.brand img \{[^}]*height:\s*(\d+)px/s', $src, $m)) {
                continue; // that page styles its mark some other way
            }

            $this->assertGreaterThanOrEqual(self::PAGE, (int) $m[1], "{$page}.blade.php mark is too small");
        }
    }

    public function test_the_admin_page_actually_renders_the_larger_mark(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('branding.icon'), false)
            ->assertSee('width:56px', false);
    }

    public function test_the_two_app_chromes_use_identical_values(): void
    {
        // The whole point: dashboard and admin must not look different.
        $component = file_get_contents(resource_path('views/components/app-logo.blade.php'));
        $adminCss = $this->adminCss();

        preg_match('/\.sidebar \.brand \.logo \{[^}]*width:\s*(\d+)px[^}]*height:\s*(\d+)px/s', $adminCss, $a);
        preg_match('/width:(\d+)px;height:(\d+)px/', $component, $c);

        $this->assertNotEmpty($a, 'admin sidebar mark rule not found');
        $this->assertNotEmpty($c, 'app chrome mark size not found');
        $this->assertSame($a[1], $c[1], 'admin and dashboard mark widths differ');
        $this->assertSame($a[2], $c[2], 'admin and dashboard mark heights differ');
        $this->assertSame((string) self::CHROME, $c[1]);
    }

    public function test_the_mark_has_no_frame_background_or_padding(): void
    {
        // The frame is decoration. Padding or a border inside it eats the mark: at 48px
        // with 2px padding and a 1px border, object-fit:contain rendered only 42px — the
        // box looked bigger while the logo barely moved.
        $adminCss = $this->adminCss();
        preg_match('/\.sidebar \.brand \.logo \{([^}]*)\}/s', $adminCss, $m);

        $this->assertNotEmpty($m, 'admin sidebar mark rule not found');
        // No tile, no border, no padding — anything here either shrinks the mark or puts a
        // visible box around it. Both were asked for and removed.
        foreach (['background', 'border', 'padding'] as $prop) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![-a-z])'.$prop.'\s*:/',
                $m[1],
                "{$prop} reintroduces a frame around the admin mark",
            );
        }

        $component = file_get_contents(resource_path('views/components/app-logo.blade.php'));
        foreach (['background:', 'border:', 'padding:'] as $prop) {
            $this->assertStringNotContainsString($prop, $component, "{$prop} reintroduces a frame around the mark");
        }
    }
}
