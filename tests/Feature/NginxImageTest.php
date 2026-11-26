<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The proxy image is pinned to a patch release, never a floating tag.
 *
 * `web` is an `image:`, not a `build:`, so `docker compose up -d --build` never touches it:
 * a host that already has nginx:1.30-alpine cached keeps running whatever patch that was,
 * however many security releases ship under the same tag. A pinned tag the host does not
 * have is pulled by `up -d`, and the changed image recreates the container — so the nginx
 * version becomes part of what `git pull` delivers. Same trap as the PHP base image.
 *
 * Floors, raise with each nginx security release on the stable branch:
 * - 1.30.4: the 18 advisories cleared in v1.23.13 (incl. CVE-2026-9256 / CVE-2026-42945).
 * - 1.30.7: "NGINX2Root", CVE-2026-123007 (HTTP/2 flow control, unauthenticated RCE, public
 *   PoC) and CVE-2026-123633 (HTTP/2 use-after-free). `http2 on` is server-level in
 *   docker/nginx.conf, so it covers the plain-HTTP proxy listener (h2c) as well as 7979.
 */
class NginxImageTest extends TestCase
{
    private const MINIMUM = '1.30.8';

    private function webImage(): string
    {
        $yml = (string) file_get_contents(base_path('docker-compose.yml'));

        $this->assertMatchesRegularExpression('/^\s+image:\s*nginx:\S+/m', $yml, 'the web service must use an nginx image');
        preg_match('/^\s+image:\s*nginx:(\S+)/m', $yml, $m);

        return $m[1];
    }

    public function test_the_proxy_image_is_pinned_to_a_patch_release(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+-alpine$/',
            $this->webImage(),
            'pin nginx to an exact patch (e.g. 1.30.7-alpine): a floating tag is never re-pulled by an upgrade',
        );
    }

    public function test_the_pinned_nginx_is_not_older_than_the_last_security_fix(): void
    {
        preg_match('/^(\d+\.\d+\.\d+)/', $this->webImage(), $m);

        $this->assertTrue(
            version_compare($m[1] ?? '0', self::MINIMUM, '>='),
            'nginx '.($m[1] ?? '?').' is older than '.self::MINIMUM.' (last nginx security release)',
        );
    }
}
