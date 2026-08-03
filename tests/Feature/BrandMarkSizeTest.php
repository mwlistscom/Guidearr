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
    private const CHROME = 63;

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
            ->assertSee('width:63px', false);
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

    public function test_the_dashboard_mark_is_not_clipped_by_flux(): void
    {
        // Flux wraps the logo slot in `[:where(&)]:h-6 ... overflow-hidden` inside an
        // `h-10` anchor — 24px with clipping — which cut the mark off top and bottom while
        // the admin sidebar, having no fixed row height, showed it whole. The inline styles
        // are what override that, so assert them in the RENDERED page, not just the source.
        $user = User::factory()->create(['email_verified_at' => now()]);

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<a[^>]*style="[^"]*height:auto[^"]*"[^>]*data-flux-sidebar-brand/',
            $html,
            'the brand row must grow instead of being pinned to h-10',
        );

        $this->assertStringContainsString(
            'style="width:63px;height:63px;overflow:visible',
            $html,
            'the logo wrapper must override Flux h-6 + overflow-hidden, or the mark is clipped',
        );
    }

    public function test_the_admin_wordmark_matches_the_dashboard(): void
    {
        // Flux renders the app-chrome wordmark as `text-sm font-medium ... text-zinc-100`,
        // i.e. 0.875rem at weight 500. The admin sidebar was 800 with tight tracking in pure
        // white, which read far bolder than the dashboard beside the same mark.
        $css = $this->adminCss();

        preg_match('/\.sidebar \.brand \{([^}]*)\}/s', $css, $m);
        $this->assertNotEmpty($m, 'admin brand rule not found');

        preg_match('/font-weight:\s*(\d+)/', $m[1], $w);
        $this->assertSame('500', $w[1] ?? '', 'admin wordmark must be weight 500, matching font-medium');

        $this->assertMatchesRegularExpression('/font-size:\s*\.875rem/', $m[1], 'must match text-sm');
        $this->assertMatchesRegularExpression('/letter-spacing:\s*normal/', $m[1], 'Flux applies no tracking');
        $this->assertDoesNotMatchRegularExpression('/color:\s*#fff\b/', $m[1], 'pure white is brighter than zinc-100');
    }

    public function test_both_brand_rows_have_identical_geometry(): void
    {
        // Flux renders the app-chrome brand row as `h-10 flex items-center px-2 gap-2`,
        // with our inline height:auto override — so: no vertical padding, .5rem sides,
        // .5rem gap. The admin sidebar carried 1.05rem of vertical padding and a .6rem
        // gap, which pushed its mark and wordmark ~17px lower than the dashboard's.
        preg_match('/\.sidebar \.brand \{([^}]*)\}/s', $this->adminCss(), $m);
        $this->assertNotEmpty($m, 'admin brand rule not found');

        $rule = $m[1];

        $this->assertMatchesRegularExpression('/gap:\s*\.5rem/', $rule, 'gap must match Flux gap-2');
        $this->assertMatchesRegularExpression('/padding:\s*0 \.5rem/', $rule, 'no vertical padding; .5rem sides, matching px-2');
        $this->assertMatchesRegularExpression('/height:\s*auto/', $rule, 'the row must grow to the mark, as the dashboard does');

        // The component carries the same height override for the Flux row.
        $component = file_get_contents(resource_path('views/components/app-logo.blade.php'));
        $this->assertStringContainsString('height:auto', $component);
    }
}
