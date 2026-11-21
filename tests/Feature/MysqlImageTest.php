<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The database image is pinned to a patch release, never a floating tag.
 *
 * `db` is an `image:`, so `docker compose up -d --build` never re-pulls it: a host that
 * already has mysql:8.4 cached keeps running whichever 8.4 patch it first downloaded, and
 * Oracle's quarterly security releases never arrive. A pinned tag the host does not have is
 * pulled by `up -d`, and the changed image recreates `db` (MySQL upgrades its own data
 * dictionary on start). Same trap as the nginx and PHP images.
 *
 * The floor only ever moves forward within the 8.4 LTS series: an install may already be on
 * the pinned version, and a downgrade after the dictionary has been upgraded is not safe.
 */
class MysqlImageTest extends TestCase
{
    private const MINIMUM = '8.4.13';

    private function dbImage(): string
    {
        $yml = (string) file_get_contents(base_path('docker-compose.yml'));

        $this->assertMatchesRegularExpression('/^\s+image:\s*mysql:\S+/m', $yml, 'the db service must use a mysql image');
        preg_match('/^\s+image:\s*mysql:(\S+)/m', $yml, $m);

        return $m[1];
    }

    public function test_the_database_image_is_pinned_to_a_patch_release(): void
    {
        $this->assertMatchesRegularExpression(
            '/^8\.4\.\d+$/',
            $this->dbImage(),
            'pin MySQL to an exact 8.4 patch (e.g. 8.4.13): a floating tag is never re-pulled by an upgrade',
        );
    }

    public function test_the_pinned_mysql_is_not_older_than_the_last_security_fix(): void
    {
        preg_match('/^(\d+\.\d+\.\d+)/', $this->dbImage(), $m);

        $this->assertTrue(
            version_compare($m[1] ?? '0', self::MINIMUM, '>='),
            'MySQL '.($m[1] ?? '?').' is older than '.self::MINIMUM.' (last MySQL security release)',
        );
    }
}
