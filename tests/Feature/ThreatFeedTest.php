<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
use App\Support\ThreatFeed;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThreatFeedTest extends TestCase
{
    use RefreshDatabase;

    /** An admin who can reach the config pane (matches BanTest's helper). */
    private function admin(): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'must_change_password' => false])->save();

        return $u;
    }

    /** Build one access-log line in the nginx `main` format. */
    private function line(string $ip, string $path, int $status, string $when = '02/Aug/2026:12:00:00 +0000'): string
    {
        return sprintf('%s - - [%s] "GET %s HTTP/1.1" %d 146 "-" "scanner" "%s"', $ip, $when, $path, $status, $ip);
    }

    private function since(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-01 00:00:00 +0000');
    }

    public function test_parses_a_standard_access_log_line(): void
    {
        $row = ThreatFeed::parseLine($this->line('203.0.113.9', '/.env', 403));

        $this->assertNotNull($row);
        $this->assertSame('203.0.113.9', $row['ip']);
        $this->assertSame('/.env', $row['path']);
        $this->assertSame(403, $row['status']);
        $this->assertSame('2026-08-02', $row['at']->format('Y-m-d'));
    }

    public function test_ignores_unparseable_lines(): void
    {
        $this->assertNull(ThreatFeed::parseLine('not a log line at all'));
        $this->assertNull(ThreatFeed::parseLine(''));
    }

    public function test_hostile_classification_is_scoped_to_the_status_the_app_returned(): void
    {
        // Denied dotfile probe.
        $this->assertTrue(ThreatFeed::isHostile('/.env', 403));
        $this->assertTrue(ThreatFeed::isHostile('/wp-login.php', 404));
        $this->assertTrue(ThreatFeed::isHostile('/index.php?option=com_rsfiles&task=rsfiles.upload', 405));

        // Same paths with a status the app would never pair them with are NOT hostile —
        // this is what stops a future legitimate route from being classified as an attack.
        $this->assertFalse(ThreatFeed::isHostile('/.env', 200));
        $this->assertFalse(ThreatFeed::isHostile('/wp-login.php', 200));

        // Ordinary misses stay off the list.
        $this->assertFalse(ThreatFeed::isHostile('/favicon.ico', 404));
        $this->assertFalse(ThreatFeed::isHostile('/m3u', 404));
    }

    public function test_counts_hostile_hits_per_ip_within_the_window(): void
    {
        $lines = [
            $this->line('203.0.113.9', '/.env', 403),
            $this->line('203.0.113.9', '/.git/config', 403),
            $this->line('203.0.113.9', '/wp-login.php', 404),
            // Outside the window — must not count.
            $this->line('203.0.113.9', '/.env', 403, '01/Jul/2026:12:00:00 +0000'),
            $this->line('198.51.100.7', '/.env', 403),
        ];

        $tally = ThreatFeed::collect($lines, $this->since());

        $this->assertSame(3, $tally['hits']['203.0.113.9']);
        $this->assertSame(1, $tally['hits']['198.51.100.7']);
    }

    public function test_a_host_that_served_a_playlist_is_never_listed(): void
    {
        // A customer's player behind a NAT that something else scanned from.
        $lines = array_merge(
            array_fill(0, 50, $this->line('203.0.113.9', '/.env', 403)),
            [$this->line('203.0.113.9', '/m3u?key=abc', 200)],
        );

        $tally = ThreatFeed::collect($lines, $this->since());

        $this->assertArrayHasKey('203.0.113.9', $tally['protected']);
        $this->assertSame([], ThreatFeed::select($tally, 1, 100));
    }

    public function test_below_threshold_addresses_are_not_listed(): void
    {
        $lines = array_fill(0, 5, $this->line('203.0.113.9', '/.env', 403));
        $tally = ThreatFeed::collect($lines, $this->since());

        $this->assertSame([], ThreatFeed::select($tally, 20, 100));
        $this->assertArrayHasKey('203.0.113.9', ThreatFeed::select($tally, 5, 100));
    }

    public function test_private_and_reserved_addresses_are_never_listed(): void
    {
        // The reverse proxy and health checks live here; listing them takes the site down.
        $lines = [];

        foreach (['192.168.3.1', '10.0.0.5', '172.23.0.1', '127.0.0.1'] as $ip) {
            $lines = array_merge($lines, array_fill(0, 30, $this->line($ip, '/.env', 403)));
        }

        $tally = ThreatFeed::collect($lines, $this->since());

        $this->assertSame([], ThreatFeed::select($tally, 20, 100));
    }

    public function test_allowlist_matches_literal_ips_and_cidrs(): void
    {
        $this->assertTrue(ThreatFeed::allowlisted('203.0.113.9', ['203.0.113.9']));
        $this->assertTrue(ThreatFeed::allowlisted('203.0.113.9', ['203.0.113.0/24']));
        $this->assertTrue(ThreatFeed::allowlisted('203.0.113.130', ['203.0.113.128/25']));
        $this->assertFalse(ThreatFeed::allowlisted('203.0.113.126', ['203.0.113.128/25']));
        $this->assertFalse(ThreatFeed::allowlisted('198.51.100.7', ['203.0.113.0/24']));
        $this->assertFalse(ThreatFeed::allowlisted('198.51.100.7', []));
    }

    public function test_allowlisted_addresses_are_excluded_from_selection(): void
    {
        $lines = array_fill(0, 30, $this->line('203.0.113.9', '/.env', 403));
        $tally = ThreatFeed::collect($lines, $this->since());

        $this->assertSame([], ThreatFeed::select($tally, 20, 100, ['203.0.113.0/24']));
    }

    public function test_selection_is_capped_and_ordered_by_hits(): void
    {
        $lines = array_merge(
            array_fill(0, 30, $this->line('203.0.113.9', '/.env', 403)),
            array_fill(0, 50, $this->line('198.51.100.7', '/.env', 403)),
        );

        $tally = ThreatFeed::collect($lines, $this->since());
        $selected = ThreatFeed::select($tally, 20, 1);

        $this->assertSame(['198.51.100.7'], array_keys($selected));
    }

    public function test_rendered_feed_is_plain_lines_with_hash_comments(): void
    {
        $body = ThreatFeed::render(['203.0.113.9' => 30, '198.51.100.7' => 50], 30, 20);
        $lines = explode("\n", trim($body));

        $addresses = array_values(array_filter($lines, static fn ($l) => ! str_starts_with($l, '#')));

        $this->assertSame(['203.0.113.9', '198.51.100.7'], $addresses);

        foreach ($addresses as $a) {
            $this->assertNotFalse(filter_var($a, FILTER_VALIDATE_IP), "not a bare IP: $a");
        }
    }

    public function test_a_url_segment_is_provisioned_automatically(): void
    {
        // Nothing to run after install or upgrade: first read mints and persists it.
        Settings::set('threat_feed_slug', '');

        $slug = Settings::threatFeedSlug();

        $this->assertGreaterThanOrEqual(16, strlen($slug));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $slug);
        $this->assertSame($slug, Settings::threatFeedSlug(), 'slug must be stable once generated');
        $this->assertStringEndsWith('/security/threat-feed/'.$slug, Settings::threatFeedUrl());
    }

    public function test_threshold_defaults_and_is_clamped(): void
    {
        Settings::set('threat_feed_min_hits', null);
        $this->assertSame(20, Settings::threatFeedMinHits());

        Settings::set('threat_feed_min_hits', 0);
        $this->assertSame(1, Settings::threatFeedMinHits());

        Settings::set('threat_feed_min_hits', 999999);
        $this->assertSame(10000, Settings::threatFeedMinHits());
    }

    public function test_endpoint_404s_when_disabled_or_token_is_wrong(): void
    {
        Settings::set('threat_feed_enabled', false);
        Settings::set('threat_feed_slug', 'secret-token-value');

        $this->get('/security/threat-feed/secret-token-value')->assertNotFound();

        Settings::set('threat_feed_enabled', true);
        $this->get('/security/threat-feed/wrong-token-value')->assertNotFound();
    }

    public function test_endpoint_serves_the_generated_file_as_text_plain(): void
    {
        Settings::set('threat_feed_enabled', true);
        Settings::set('threat_feed_slug', 'secret-token-value');

        @mkdir(dirname(ThreatFeed::path()), 0775, true);
        file_put_contents(ThreatFeed::path(), "# Guidearr threat feed\n203.0.113.9\n");

        $this->get('/security/threat-feed/secret-token-value')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('203.0.113.9');

        @unlink(ThreatFeed::path());
    }

    public function test_admin_can_change_the_url_and_threshold(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'links_base_url' => '',
            'serve_max_ips' => 10,
            'serve_window_hours' => 4,
            'worker_limit' => 1,
            'threat_feed_enabled' => '1',
            'threat_feed_slug' => 'my-custom-feed-path',
            'threat_feed_min_hits' => 5,
        ])->assertRedirect(route('admin.config'));

        $this->assertTrue(Settings::threatFeedEnabled());
        $this->assertSame('my-custom-feed-path', Settings::threatFeedSlug());
        $this->assertSame(5, Settings::threatFeedMinHits());
    }

    public function test_config_page_offers_a_copy_button_for_the_feed_url(): void
    {
        Settings::set('threat_feed_slug', 'copyable-slug-value');

        $this->actingAs($this->admin())
            ->get(route('admin.config'))
            ->assertOk()
            // The button targets the element holding the URL...
            ->assertSee('data-copy="tfUrl"', false)
            ->assertSee('id="tfUrl"', false)
            // ...and must not submit the surrounding settings form.
            ->assertSee('type="button" class="copybtn"', false)
            // Clipboard API for the normal HTTPS case...
            ->assertSee('navigator.clipboard', false)
            // ...and a fallback, because the stack also publishes a plain-HTTP port
            // where navigator.clipboard is unavailable and the button would be dead.
            ->assertSee('execCommand', false);
    }

    public function test_config_page_renders_both_controls(): void
    {
        Settings::set('threat_feed_slug', 'visible-slug-value');
        Settings::set('threat_feed_min_hits', 33);

        $this->actingAs($this->admin())
            ->get(route('admin.config'))
            ->assertOk()
            ->assertSee('Threat feed')
            // The editable URL secret, and the origin it hangs off.
            ->assertSee('name="threat_feed_slug"', false)
            ->assertSee('visible-slug-value', false)
            ->assertSee('/security/threat-feed/', false)
            // The failure threshold.
            ->assertSee('name="threat_feed_min_hits"', false)
            ->assertSee('value="33"', false);
    }

    public function test_a_settings_post_without_the_threat_feed_section_is_unaffected(): void
    {
        // The pane predates this feature; an older or partial submission must still save
        // and must not silently switch the feed off.
        Settings::set('threat_feed_enabled', true);
        Settings::set('threat_feed_slug', 'kept-slug-value');

        $this->actingAs($this->admin())->put(route('admin.settings.update'), [
            'links_base_url' => '',
            'serve_max_ips' => 10,
            'serve_window_hours' => 4,
            'worker_limit' => 6,
        ])->assertRedirect(route('admin.config'));

        $this->assertSame(6, Settings::workerLimit());
        $this->assertTrue(Settings::threatFeedEnabled());
        $this->assertSame('kept-slug-value', Settings::threatFeedSlug());
    }

    public function test_admin_cannot_set_a_url_segment_with_slashes_or_too_short(): void
    {
        $admin = $this->admin();
        Settings::set('threat_feed_slug', 'original-value');

        $base = [
            'links_base_url' => '',
            'serve_max_ips' => 10,
            'serve_window_hours' => 4,
            'worker_limit' => 1,
            'threat_feed_min_hits' => 20,
        ];

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $base + ['threat_feed_slug' => 'has/slash/segments'])
            ->assertSessionHasErrors('threat_feed_slug');

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $base + ['threat_feed_slug' => 'short'])
            ->assertSessionHasErrors('threat_feed_slug');

        $this->assertSame('original-value', Settings::threatFeedSlug());
    }
}
