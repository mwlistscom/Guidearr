<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Four layers each cap an upload, and they have to agree from the outside in:
 *
 *   nginx client_max_body_size  >=  post_max_size  >=  upload_max_filesize  >=  app rule
 *
 * Get that order wrong and a request dies at the wrong layer with a useless error. When
 * post_max_size sat below nginx's limit, PHP aborted without reading the body, nginx
 * waited out fastcgi_read_timeout, and the user got a 504 five minutes later instead of
 * "this file is too big".
 *
 * These are parsed from the config files rather than read from ini_get(), so the check
 * holds in CI too — CI runs on its own PHP with its own defaults.
 */
class UploadLimitsTest extends TestCase
{
    private function bytes(string $value): int
    {
        $value = trim($value);
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private function phpIni(string $key): int
    {
        $ini = file_get_contents(base_path('docker/php.ini'));
        $this->assertMatchesRegularExpression("/^{$key}\s*=/m", $ini, "docker/php.ini does not set {$key}");
        preg_match("/^{$key}\s*=\s*(\S+)/m", $ini, $m);

        return $this->bytes($m[1]);
    }

    public function test_php_ini_is_shipped_in_the_image(): void
    {
        // The official php:*-fpm images ship no php.ini at all — without this COPY, PHP
        // silently falls back to a 2M upload cap.
        $this->assertFileExists(base_path('docker/php.ini'));
        $this->assertStringContainsString(
            'COPY docker/php.ini',
            file_get_contents(base_path('Dockerfile')),
            'the Dockerfile must install docker/php.ini or the limits never apply',
        );
    }

    public function test_the_layers_agree_from_the_outside_in(): void
    {
        preg_match('/client_max_body_size\s+(\S+?);/', file_get_contents(base_path('docker/nginx.conf')), $m);
        $nginx = $this->bytes($m[1] ?? '0');

        $post = $this->phpIni('post_max_size');
        $upload = $this->phpIni('upload_max_filesize');

        // The app's own rule, in kilobytes, is the tightest so it produces the error message.
        preg_match('/max:(\d+)/', file_get_contents(app_path('Http/Controllers/BrandingController.php')), $m);
        $appRule = ((int) ($m[1] ?? 0)) * 1024;

        $this->assertGreaterThan(0, $nginx, 'nginx client_max_body_size not found');
        $this->assertGreaterThan(0, $appRule, 'branding upload rule not found');

        $this->assertGreaterThanOrEqual($post, $nginx, 'nginx must accept at least post_max_size');
        $this->assertGreaterThanOrEqual($upload, $post, 'post_max_size must cover upload_max_filesize');
        $this->assertGreaterThanOrEqual($appRule, $upload, 'PHP must accept what the app says it accepts');
    }

    public function test_memory_allows_resizing_a_max_sized_upload(): void
    {
        // The downscaler declines rather than dying when a decode won't fit, so too small a
        // limit silently disables resizing on exactly the images that most need it.
        $this->assertGreaterThanOrEqual(
            256 * 1024 * 1024,
            $this->phpIni('memory_limit'),
            'memory_limit too low for the downscaler to handle a full-sized upload',
        );
    }
}
