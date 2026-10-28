<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * One scanner must not be able to take every PHP worker.
 *
 * The image's PHP-FPM pool has five workers. Scanners requesting made-up paths at 150+/s
 * each got a Laravel 404 (~45 ms), so a few of them held the whole pool and real requests
 * queued behind the scan ("server reached pm.max_children", about twenty times a day).
 *
 * Two halves: a larger pool (docker/php-fpm.conf) and a per-IP limit on PHP requests in
 * docker/nginx.conf. The limit is the half that matters — without it a bigger pool only
 * takes a bigger scan to fill.
 *
 * Parsed from the config files, like UploadLimitsTest, so this holds in CI as well. A live
 * check (throwaway nginx + php-fpm, 100 parallel requests from one forwarded IP) gave 34
 * answered and 66 refused with 429; static files and private clients were never refused.
 */
class RequestCapacityTest extends TestCase
{
    private function nginx(): string
    {
        return (string) file_get_contents(base_path('docker/nginx.conf'));
    }

    /** The body of the first `location <match> {` block, braces balanced. */
    private function location(string $match): string
    {
        $conf = $this->nginx();
        $start = strpos($conf, "location {$match} {");
        $this->assertNotFalse($start, "docker/nginx.conf has no `location {$match}` block");

        $depth = 0;
        for ($i = strpos($conf, '{', $start); $i < strlen($conf); $i++) {
            $depth += match ($conf[$i]) {
                '{' => 1, '}' => -1, default => 0
            };
            if ($depth === 0) {
                return substr($conf, $start, $i - $start + 1);
            }
        }

        $this->fail("unbalanced braces after `location {$match}`");
    }

    public function test_the_pool_file_is_installed_after_the_images_own(): void
    {
        $df = (string) file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression(
            '#^COPY docker/php-fpm\.conf /usr/local/etc/php-fpm\.d/(\S+\.conf)$#m',
            $df,
            'the Dockerfile must install docker/php-fpm.conf into php-fpm.d',
        );
        preg_match('#^COPY docker/php-fpm\.conf /usr/local/etc/php-fpm\.d/(\S+\.conf)$#m', $df, $m);

        // FPM loads php-fpm.d/*.conf in name order and the last [www] value wins. The image
        // ships www.conf and zz-docker.conf; a name sorting before either is silently overridden.
        foreach (['www.conf', 'zz-docker.conf'] as $theirs) {
            $this->assertGreaterThan(0, strcmp($m[1], $theirs), "{$m[1]} must sort after the image's {$theirs}");
        }
    }

    public function test_the_pool_is_larger_than_the_image_default(): void
    {
        $pool = (string) file_get_contents(base_path('docker/php-fpm.conf'));

        $this->assertMatchesRegularExpression('/^\[www\]$/m', $pool, 'the overrides must be in the [www] pool');
        $this->assertMatchesRegularExpression('/^pm\.max_children\s*=\s*\d+/m', $pool);
        preg_match('/^pm\.max_children\s*=\s*(\d+)/m', $pool, $m);

        $this->assertGreaterThan(5, (int) $m[1], 'five is the image default that a scan filled');
    }

    public function test_php_requests_are_limited_per_client_address(): void
    {
        $php = $this->location('~ \.php$');

        $this->assertMatchesRegularExpression('/^\s*limit_req zone=php\b/m', $php, 'the PHP location must apply the per-IP limit');
        $this->assertMatchesRegularExpression('/^\s*limit_req_status 429;/m', $php, 'answer over-limit requests 429, not the default 503');

        // Keyed on the client address (after real_ip), through the exemption map.
        $this->assertMatchesRegularExpression('/^limit_req_zone \$php_limit_key zone=php:\S+ rate=\d+r\/s;/m', $this->nginx());
        $this->assertMatchesRegularExpression('/map \$php_limit_exempt \$php_limit_key \{\s*0 \$binary_remote_addr;\s*1 "";/', $this->nginx());
    }

    public function test_static_files_are_not_limited(): void
    {
        // A page pulls several assets at once; counting them would refuse real visitors.
        $this->assertStringNotContainsString('limit_req', $this->location('/'));
        $this->assertDoesNotMatchRegularExpression('/^    limit_req\b/m', $this->nginx(), 'no server-wide limit_req');
    }

    public function test_every_trusted_proxy_range_is_exempt(): void
    {
        // If real_ip does not apply (a proxy outside docker/real-ip.conf, or a request nginx
        // rejects before reading its headers), the client appears as the proxy's own address.
        // Limiting that one address would throttle the whole site as a single visitor.
        preg_match_all('/^set_real_ip_from\s+(\S+);/m', (string) file_get_contents(base_path('docker/real-ip.conf')), $trusted);
        preg_match('/geo \$php_limit_exempt \{(.*?)\}/s', $this->nginx(), $geo);

        $this->assertNotEmpty($trusted[1], 'docker/real-ip.conf trusts no ranges');
        $this->assertNotEmpty($geo, 'docker/nginx.conf has no $php_limit_exempt geo block');
        $this->assertMatchesRegularExpression('/^\s*default\s+0;/m', $geo[1], 'public addresses must not be exempt');

        foreach ($trusted[1] as $range) {
            $this->assertMatchesRegularExpression(
                '/^\s*'.preg_quote($range, '/').'\s+1;/m',
                $geo[1],
                "{$range} is a trusted proxy range but is not exempt from the PHP limit",
            );
        }
    }

    public function test_refusals_do_not_flood_the_error_log(): void
    {
        // A scan is thousands of refusals. At the default `error` level each one would be an
        // error-log line; `info` sits below the log's `warn` threshold. The 429s still appear
        // in the access log, which is what the threat feed reads.
        $this->assertMatchesRegularExpression('/^\s*error_log\s+\S+\s+warn;/m', $this->nginx());
        $this->assertMatchesRegularExpression('/^\s*limit_req_log_level (info|notice);/m', $this->location('~ \.php$'));
    }
}
