<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The PHP base image is pinned to a patch release, never a floating tag.
 *
 * A floating tag such as php:8.4-fpm-alpine is only re-fetched with `--pull`, so the
 * documented upgrade (`git pull && docker compose up -d --build`) rebuilt on whatever base
 * the host already had cached — a PHP security release never arrived. A pinned tag the host
 * does not have is always fetched, which makes the PHP version part of what a pull delivers.
 *
 * The floor is the php2shell fix (CVE-2026-109618 / CVE-2026-109619): unauthenticated RCE
 * through a crafted multipart body, parsed before any application code runs.
 */
class PhpBaseImageTest extends TestCase
{
    private const MINIMUM = '8.4.27';

    private function baseTag(): string
    {
        $df = (string) file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression('/^FROM php:\S+/m', $df, 'the app stage must build FROM a php image');
        preg_match_all('/^FROM php:(\S+)/m', $df, $m);

        return end($m[1]);
    }

    public function test_the_base_image_is_pinned_to_a_patch_release(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+-fpm-alpine/',
            $this->baseTag(),
            'pin the PHP base to an exact patch (e.g. 8.4.27-fpm-alpine): a floating tag is not re-fetched by --build',
        );
    }

    public function test_the_pinned_php_is_not_older_than_the_last_security_fix(): void
    {
        preg_match('/^(\d+\.\d+\.\d+)/', $this->baseTag(), $m);

        $this->assertTrue(
            version_compare($m[1] ?? '0', self::MINIMUM, '>='),
            "PHP base {$m[1]} is older than ".self::MINIMUM.' (php2shell fix)',
        );
    }
}
