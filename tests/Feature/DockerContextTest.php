<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The build context is everything .dockerignore does not exclude, and it is sent to the
 * daemon on every build.
 *
 * The Dockerfile needs very little of the repository — the application code is bind-mounted
 * at runtime rather than copied in — so nearly all of a 2.4GB context was waste, most of it
 * the provider feed stores under storage/.
 *
 * The risk this pins is the other direction: excluding something a COPY needs. That fails
 * the build with a "not found" naming a file the operator can plainly see on disk, which is
 * exactly how the missing vendor/ in the assets stage used to present before v1.23.12.
 */
class DockerContextTest extends TestCase
{
    private function dockerignore(): string
    {
        return (string) file_get_contents(base_path('.dockerignore'));
    }

    /** @return list<string> patterns, comments and blanks stripped */
    private function patterns(): array
    {
        $lines = preg_split('/\R/', $this->dockerignore()) ?: [];

        return array_values(array_filter(array_map('trim', $lines), function (string $l): bool {
            return $l !== '' && ! str_starts_with($l, '#');
        }));
    }

    /** @return list<string> every path COPYed from the build context (not from another stage) */
    private function contextSources(): array
    {
        preg_match_all('/^COPY\s+(?!--from)(.+)$/m', (string) file_get_contents(base_path('Dockerfile')), $m);

        $sources = [];

        foreach ($m[1] as $line) {
            $args = preg_split('/\s+/', trim(rtrim($line, '\\'))) ?: [];
            // The last argument is the destination; everything before it is a source.
            array_pop($args);
            foreach ($args as $a) {
                if ($a !== '') {
                    $sources[] = $a;
                }
            }
        }

        return $sources;
    }

    public function test_a_dockerignore_exists(): void
    {
        $this->assertFileExists(base_path('.dockerignore'), 'without one the whole tree is sent on every build');
    }

    public function test_nothing_the_dockerfile_copies_is_excluded(): void
    {
        $patterns = $this->patterns();
        $sources = $this->contextSources();

        $this->assertNotEmpty($sources, 'expected the Dockerfile to copy something from the context');

        foreach ($sources as $source) {
            $path = ltrim($source, './');

            foreach ($patterns as $pattern) {
                $p = rtrim($pattern, '/');

                // A pattern excludes a source if it matches the path itself or any parent
                // directory of it — "storage" takes storage/app/x.sqlite with it.
                $segments = explode('/', $path);

                for ($i = count($segments); $i > 0; $i--) {
                    $prefix = implode('/', array_slice($segments, 0, $i));

                    $this->assertFalse(
                        fnmatch($p, $prefix),
                        ".dockerignore pattern '{$pattern}' excludes '{$source}', which the Dockerfile COPYs — the build would fail with a confusing 'not found'",
                    );
                }
            }
        }
    }

    public function test_the_heavy_and_sensitive_paths_are_excluded(): void
    {
        $patterns = array_map(fn (string $p): string => rtrim($p, '/'), $this->patterns());

        // storage holds the provider/playlist SQLite stores and is gigabytes on a busy
        // install; vendor and node_modules are rebuilt inside the image; .env and certs
        // are secrets that have no business being handed to the daemon.
        foreach (['storage', 'vendor', 'node_modules', '.env', 'certs', '.git'] as $expected) {
            $this->assertContains(
                $expected,
                $patterns,
                "'{$expected}' should not be part of the build context",
            );
        }
    }
}
