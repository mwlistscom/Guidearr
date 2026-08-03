<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Frontend assets are compiled during the image build and published at container start.
 *
 * The subtlety worth protecting: compose bind-mounts ./ over /var/www/html, so assets the
 * image places at public/build are **hidden** by the host directory at runtime. Building
 * them straight into public/build looks correct and does nothing. They are staged outside
 * that path and copied in by the entrypoint, which is what makes
 * `docker compose up -d --build` actually refresh them.
 *
 * Before this, an upgraded install kept whatever stylesheet it already had, so any new
 * CSS class silently had no effect — which is why the brand mark had to be styled with
 * explicit CSS instead of Tailwind utilities.
 */
class AssetBuildTest extends TestCase
{
    private function dockerfile(): string
    {
        return (string) file_get_contents(base_path('Dockerfile'));
    }

    public function test_the_image_compiles_the_frontend(): void
    {
        $df = $this->dockerfile();

        $this->assertStringContainsString('npm ci', $df, 'the build stage must install dependencies');
        $this->assertStringContainsString('npm run build', $df, 'the image must compile the frontend');
    }

    public function test_built_assets_are_staged_outside_the_bind_mount(): void
    {
        $df = $this->dockerfile();

        $this->assertMatchesRegularExpression(
            '/COPY --from=assets \S+ \/opt\/guidearr\/build/',
            $df,
            'assets must be staged outside /var/www/html — the bind mount would hide them',
        );

        $this->assertDoesNotMatchRegularExpression(
            '#COPY --from=assets \S+ /var/www/html#',
            $df,
            'copying assets into /var/www/html is a no-op: compose bind-mounts ./ over it',
        );
    }

    public function test_the_entrypoint_publishes_them_and_hands_over(): void
    {
        $this->assertFileExists(base_path('docker/entrypoint.sh'));

        $script = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertStringContainsString('/opt/guidearr/build', $script, 'entrypoint must read the staged assets');
        $this->assertStringContainsString('public/build', $script, 'entrypoint must publish into public/build');
        $this->assertStringContainsString('exec docker-php-entrypoint', $script, 'it must hand over to the stock entrypoint');

        $this->assertStringContainsString(
            'ENTRYPOINT ["guidearr-entrypoint"]',
            $this->dockerfile(),
            'the Dockerfile must actually use the entrypoint',
        );
    }

    public function test_publishing_assets_can_never_stop_a_container_starting(): void
    {
        $script = (string) file_get_contents(base_path('docker/entrypoint.sh'));

        // `set -e` would turn any failed copy into a container that refuses to boot. The
        // worker and scheduler run as www-data and may not own public/build; they must
        // skip quietly rather than die.
        $this->assertDoesNotMatchRegularExpression('/^\s*set -e/m', $script, 'set -e would make a failed copy fatal');
        $this->assertStringContainsString('return 0', $script, 'the publish step must bail out rather than fail');
    }

    public function test_tailwind_source_paths_are_available_to_the_build_stage(): void
    {
        // resources/css/app.css imports flux.css outright and @sources vendor stubs. If the
        // build stage lacks them the styles are silently lost (or the build fails).
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $df = $this->dockerfile();

        preg_match_all("/@(?:source|import)\s+'([^']+)'/", $css, $m);

        $checked = 0;

        foreach ($m[1] as $ref) {
            if (! str_contains($ref, 'vendor/')) {
                continue;
            }

            $relative = preg_replace('#^.*?vendor/#', 'vendor/', $ref);
            $concrete = base_path(preg_replace('#/\*.*$#', '', $relative));

            // Optional packages (flux-pro, for instance) may not be installed. app.css
            // @sources them regardless, and Tailwind simply finds nothing — so only
            // require a COPY for the ones actually present. Install one later and this
            // starts demanding it, which is the point.
            if (! file_exists($concrete)) {
                continue;
            }

            $parts = explode('/', $relative);
            $needle = implode('/', array_slice($parts, 0, 3));

            $this->assertStringContainsString(
                $needle,
                $df,
                "the build stage does not copy {$needle}, which resources/css/app.css needs",
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'expected at least one vendor source to verify');
    }
}
