<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PHP dependencies are installed during the image build and published at container start.
 *
 * The subtlety worth protecting is the same one AssetBuildTest guards for the frontend:
 * compose bind-mounts ./ over /var/www/html, so a vendor/ the image installs at the obvious
 * place is **hidden** by the host directory at runtime. It is staged outside that path and
 * copied in by the entrypoint, which is what makes `docker compose up -d --build` actually
 * refresh the packages.
 *
 * Before v1.23.12 the image never ran composer at all. vendor/ is gitignored, and the
 * documented upgrade is `git pull && docker compose up -d --build` — which pulled a new
 * composer.lock and left the old packages sitting on disk. The security bump in v1.23.12
 * (guzzle, psr7, commonmark, livewire) would have shipped to git and reached nobody.
 */
class VendorBuildTest extends TestCase
{
    private function dockerfile(): string
    {
        return (string) file_get_contents(base_path('Dockerfile'));
    }

    private function entrypoint(): string
    {
        return (string) file_get_contents(base_path('docker/entrypoint.sh'));
    }

    public function test_the_image_installs_php_dependencies(): void
    {
        $df = $this->dockerfile();

        $this->assertMatchesRegularExpression(
            '/RUN composer install/',
            $df,
            'the image must install the dependencies, or an upgrade never refreshes them',
        );

        $this->assertStringContainsString(
            '--no-dev',
            $df,
            'production installs have no use for phpunit and pint',
        );
    }

    public function test_vendor_is_staged_outside_the_bind_mount(): void
    {
        $df = $this->dockerfile();

        $this->assertMatchesRegularExpression(
            '#COPY --from=deps \S+ /opt/guidearr/vendor#',
            $df,
            'vendor must be staged outside /var/www/html — the bind mount would hide it',
        );

        $this->assertDoesNotMatchRegularExpression(
            '#COPY --from=deps \S+ /var/www/html#',
            $df,
            'copying vendor into /var/www/html is a no-op: compose bind-mounts ./ over it',
        );
    }

    public function test_the_build_does_not_need_a_vendor_directory_on_the_host(): void
    {
        // The assets stage needs flux.css and the pagination views. Taking them from the
        // build context meant a fresh clone could not build at all — vendor/ is gitignored,
        // so `docker compose up -d --build` failed on a COPY before it reached anything
        // else. They come from the deps stage now, so a clean checkout builds.
        $df = $this->dockerfile();

        preg_match_all('/^COPY\s+(?!--from)(\S+)/m', $df, $m);

        foreach ($m[1] as $source) {
            $this->assertStringNotContainsString(
                'vendor/',
                $source,
                "the build context must not supply {$source}: vendor/ is gitignored, so a fresh clone does not have it",
            );
        }
    }

    public function test_the_entrypoint_installs_them_keyed_on_the_lock_file(): void
    {
        $script = $this->entrypoint();

        $this->assertStringContainsString('/opt/guidearr/vendor', $script, 'entrypoint must read the staged vendor');
        $this->assertStringContainsString('/var/www/html/vendor', $script, 'entrypoint must publish into vendor/');

        // composer.lock is what says which packages the code on disk expects. Comparing it
        // is what makes an upgrade reinstall, and what leaves a matching local install
        // (dev dependencies and all) alone.
        $this->assertStringContainsString('composer.lock', $script, 'the install must be keyed on composer.lock');
        $this->assertStringContainsString(
            '.guidearr-lock',
            $script,
            'the entrypoint must compare the marker the build wrote',
        );

        $this->assertStringContainsString(
            '.guidearr-lock',
            $this->dockerfile(),
            'the build must record which lock file it installed from',
        );
    }

    public function test_a_stale_image_says_so_rather_than_installing_the_wrong_packages(): void
    {
        // `git pull` without `--build` leaves the image holding the previous packages while
        // composer.lock on disk asks for new ones. Installing the staged vendor there would
        // silently pin the old versions — the exact failure this release exists to fix.
        $script = $this->entrypoint();

        $this->assertMatchesRegularExpression(
            '/--build/',
            $script,
            'a lock mismatch must tell the operator to rebuild',
        );
    }

    public function test_stale_package_discovery_is_dropped_with_the_old_packages(): void
    {
        // bootstrap/cache/packages.php lists the providers of the packages that were there
        // a moment ago. Laravel rebuilds it on demand once it is gone.
        $this->assertStringContainsString(
            'bootstrap/cache/packages.php',
            $this->entrypoint(),
            'cached package discovery must not outlive the packages it describes',
        );
    }

    public function test_installing_vendor_can_never_stop_a_container_starting(): void
    {
        $script = $this->entrypoint();

        // `set -e` would turn any failed copy into a container that refuses to boot.
        $this->assertDoesNotMatchRegularExpression('/^\s*set -e/m', $script, 'set -e would make a failed copy fatal');

        // The swap is a rename, so a request in flight sees one whole tree or the other.
        // Whatever happens, the app must never be left with no vendor at all.
        $this->assertMatchesRegularExpression(
            '/mv "\$tmp" "\$VENDOR_DST"/',
            $script,
            'vendor must be swapped in by rename, not copied over in place',
        );
    }
}
