<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The php:*-fpm images ship no php.ini, so anything docker/php.ini leaves unset runs on
 * PHP's compiled-in DEVELOPMENT defaults. With display_errors on, an engine warning raised
 * before Laravel runs is printed into the response body and the real status can no longer
 * be sent; with expose_php on, every response names the PHP version. Parsed from the files,
 * not ini_get(), so the check holds in CI on its own PHP.
 */
class PhpErrorDisplayTest extends TestCase
{
    private function phpIni(string $key): string
    {
        $ini = file_get_contents(base_path('docker/php.ini'));
        $this->assertMatchesRegularExpression("/^{$key}\s*=/m", $ini, "docker/php.ini does not set {$key}");
        preg_match("/^{$key}\s*=\s*(\S+)/m", $ini, $m);

        return strtolower($m[1]);
    }

    public function test_php_does_not_print_errors_to_the_client(): void
    {
        $this->assertSame('off', $this->phpIni('display_errors'));
        // Startup warnings ("Missing boundary", "POST Content-Length exceeds the limit")
        // are governed by this one, not display_errors.
        $this->assertSame('off', $this->phpIni('display_startup_errors'));
    }

    public function test_php_errors_still_go_somewhere(): void
    {
        // Off + Off would be silent. With no error_log set, FPM forwards to stderr, i.e.
        // `docker compose logs app`.
        $this->assertSame('on', $this->phpIni('log_errors'));
    }

    public function test_the_php_version_is_not_announced(): void
    {
        $this->assertSame('off', $this->phpIni('expose_php'));

        // Belt and braces for an install that pulled nginx.conf without rebuilding the image.
        $nginx = file_get_contents(base_path('docker/nginx.conf'));
        preg_match('/location ~ \\\\\.php\$ \{(.*?)\n    \}/s', $nginx, $m);
        $this->assertNotEmpty($m, 'could not find the \.php$ location in docker/nginx.conf');
        $this->assertMatchesRegularExpression('/^\s*fastcgi_hide_header\s+X-Powered-By;/m', $m[1]);
    }
}
