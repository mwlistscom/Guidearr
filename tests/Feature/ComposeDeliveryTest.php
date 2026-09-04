<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * docker-compose.yml is tracked, which is the only reason an upgrade can change it.
 *
 * It used to be gitignored — it held each install's ports and database passwords — so
 * `git pull` could never touch it. The tracked copy was docker-compose.yml.example, which
 * an install copies once at setup and never reads again. Every change to a service was
 * therefore undeliverable: v1.23.13 moved the proxy off an nginx with 18 open advisories
 * and could only ask each operator to edit their own file by hand.
 *
 * Keeping it tracked means keeping it free of anything install-specific. These tests pin
 * that, and the matching requirement that setup.sh actually writes what it interpolates —
 * a variable referenced here and never written is what left MYSQL_ROOT_PASSWORD empty and
 * stopped a fresh install's database from initialising at all.
 */
class ComposeDeliveryTest extends TestCase
{
    private function compose(): string
    {
        return (string) file_get_contents(base_path('docker-compose.yml'));
    }

    private function gitignore(): string
    {
        return (string) file_get_contents(base_path('.gitignore'));
    }

    /** @return list<string> every ${VAR} the compose file interpolates */
    private function variables(): array
    {
        preg_match_all('/\$\{([A-Z_][A-Z0-9_]*)/', $this->compose(), $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> those with no `:-default`, so they must come from .env */
    private function requiredVariables(): array
    {
        preg_match_all('/\$\{([A-Z_][A-Z0-9_]*)([^}]*)\}/', $this->compose(), $m, PREG_SET_ORDER);

        $required = [];

        foreach ($m as $match) {
            if (! str_starts_with($match[2], ':-')) {
                $required[] = $match[1];
            }
        }

        return array_values(array_unique($required));
    }

    public function test_the_compose_file_is_tracked_not_ignored(): void
    {
        $this->assertFileExists(base_path('docker-compose.yml'));

        $lines = array_map('trim', preg_split('/\R/', $this->gitignore()) ?: []);

        $this->assertNotContains(
            'docker-compose.yml',
            $lines,
            'ignoring it is the delivery gap: no upgrade could ever change a service definition',
        );
        $this->assertNotContains('/docker-compose.yml', $lines);

        // The seam for local changes, so nobody has to edit the tracked file and conflict
        // with the next pull. Compose merges it automatically when present.
        $this->assertContains(
            'docker-compose.override.yml',
            $lines,
            'the override file is where install-specific changes belong, and must not be committed',
        );
    }

    public function test_there_is_only_one_compose_file(): void
    {
        // Two sources of truth is what caused this: the tracked .example drifted from the
        // live file the moment an install copied it.
        $this->assertFileDoesNotExist(
            base_path('docker-compose.yml.example'),
            'the real file is tracked now — an example alongside it can only drift',
        );
    }

    public function test_no_secret_is_written_into_the_tracked_file(): void
    {
        preg_match_all('/^\s*MYSQL_(?:ROOT_)?PASSWORD:\s*(\S+)/m', $this->compose(), $m);

        $this->assertNotEmpty($m[1], 'expected the db service to set its passwords');

        foreach ($m[1] as $value) {
            $this->assertStringStartsWith(
                '${',
                $value,
                'a password literal in a tracked file would be committed and shared by every install',
            );
        }
    }

    public function test_missing_credentials_stop_the_stack_rather_than_weaken_it(): void
    {
        // `${VAR:?}` makes compose refuse to interpolate. Without it a missing value
        // becomes empty: MySQL would be initialised with a blank password, or an install
        // whose .env drifted would come up unable to reach its own database.
        //
        // The message after `:?` is deliberately empty. Text there reads as a hardcoded
        // value to secret scanners — GitGuardian failed PR #100 over exactly that, twice,
        // on lines that contain no secret at all — and Compose already names the variable
        // it could not resolve. Pinned so nobody helpfully adds a message back.
        foreach (['DB_PASSWORD', 'DB_ROOT_PASSWORD'] as $secret) {
            $this->assertMatchesRegularExpression(
                '/\$\{'.$secret.':\?\}/',
                $this->compose(),
                "{$secret} must use \${...:?} exactly — a message after :? trips secret scanners",
            );
        }
    }

    public function test_setup_writes_every_variable_the_compose_file_requires(): void
    {
        // The exact bug this pins: docker-compose.yml.example referenced ${DB_ROOT_PASSWORD}
        // and setup.sh never wrote it, so MySQL refused to initialise and a fresh install
        // could not start its database at all.
        $setup = (string) file_get_contents(base_path('setup.sh'));

        $required = $this->requiredVariables();

        $this->assertNotEmpty($required, 'expected at least one required variable');

        foreach ($required as $var) {
            $this->assertMatchesRegularExpression(
                '/^'.$var.'=/m',
                $setup,
                "docker-compose.yml requires {$var}, but setup.sh never writes it to .env",
            );
        }
    }

    public function test_install_specific_values_are_interpolated_rather_than_hardcoded(): void
    {
        // Ports and binds differ per install and were the other half of what made the file
        // unshareable. They carry defaults, so an .env without them still works.
        foreach (['TLS_PORT', 'HTTP_BIND', 'HTTP_PORT'] as $var) {
            $this->assertContains(
                $var,
                $this->variables(),
                "{$var} should come from .env, or an operator has to edit the tracked file",
            );
        }
    }

    public function test_the_migration_helper_ships_with_it(): void
    {
        // Existing installs have an untracked docker-compose.yml that blocks the pull, and
        // their values have to reach .env or the stack comes up wrong.
        $this->assertFileExists(
            base_path('docker/migrate-compose.sh'),
            'existing installs need a way to carry their values across',
        );
    }
}
