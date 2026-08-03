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
        // About the MARK, not the row. The row carries padding on purpose — that is what
        // positions the brand block, and both chromes share it. What must stay clean is
        // the box immediately around the icon: a tile, border or inner padding there is
        // what put a visible box around it and shrank the mark inside its own frame.
        preg_match('/\.sidebar \.brand \.logo \{([^}]*)\}/s', $this->adminCss(), $m);
        $this->assertNotEmpty($m, 'admin sidebar mark rule not found');

        $component = file_get_contents(resource_path('views/components/app-logo.blade.php'));
        preg_match("/\\\$frame = '([^']*)'/", $component, $f);
        preg_match("/\\\$mark = '([^']*)'/", $component, $k);
        $this->assertNotEmpty($f, 'the mark frame style not found');
        $this->assertNotEmpty($k, 'the mark style not found');

        foreach (['background', 'border', 'padding'] as $prop) {
            $pattern = '/(?<![-a-z])'.$prop.'\s*:/';

            $this->assertDoesNotMatchRegularExpression($pattern, $m[1], "{$prop} frames the admin mark");
            $this->assertDoesNotMatchRegularExpression($pattern, $f[1], "{$prop} frames the app-chrome mark");
            $this->assertDoesNotMatchRegularExpression($pattern, $k[1], "{$prop} is set on the image itself");
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
        // The two rows are styled by unrelated systems — plain CSS in the admin sidebar,
        // Flux utilities overridden inline in the component — so the only thing keeping
        // them aligned is that they carry the same values. Compare them directly rather
        // than pinning a number, so either can be retuned as long as both move together.
        preg_match('/\.sidebar \.brand \{([^}]*)\}/s', $this->adminCss(), $m);
        $this->assertNotEmpty($m, 'admin brand rule not found');

        $component = file_get_contents(resource_path('views/components/app-logo.blade.php'));
        preg_match("/\\\$row = '([^']*)'/", $component, $r);
        $this->assertNotEmpty($r, 'the component brand-row style not found');

        foreach (['padding', 'height'] as $prop) {
            preg_match('/(?<![-a-z])'.$prop.':\s*([^;]+)/', $m[1], $a);
            preg_match('/(?<![-a-z])'.$prop.':\s*([^;]+)/', $r[1], $b);

            $this->assertNotEmpty($a, "admin brand rule has no {$prop}");
            $this->assertNotEmpty($b, "component brand row has no {$prop}");
            $this->assertSame(
                trim($a[1]),
                trim($b[1]),
                "{$prop} differs between the admin sidebar and the app chrome — the rows will not line up",
            );
        }

        // Gap and type are matched too; Flux uses gap-2 / text-sm / font-medium.
        $this->assertMatchesRegularExpression('/gap:\s*\.5rem/', $m[1], 'gap must match Flux gap-2');
        $this->assertMatchesRegularExpression('/font-size:\s*\.875rem/', $m[1], 'must match text-sm');
    }
}
