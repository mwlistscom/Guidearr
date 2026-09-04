<?php

namespace Tests\Feature;

use App\Support\OutboundUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Provider URLs are fetched BY THE SERVER, so an unrestricted host is an SSRF.
 *
 * Anyone can register (approval is off by default), add a provider pointing at
 * `http://127.0.0.1:9000/`, `http://db:3306/` or a LAN address, and have this host reach it.
 * It is not even blind: the validator distinguishes "connection refused" from "fetched, but
 * did not look like an M3U" and reports the byte count — a working internal port scanner —
 * and anything whose first bytes resemble a playlist is imported and displayed back.
 *
 * Every assertion here uses IP literals or names in /etc/hosts, so the suite still means
 * something with no network (which is how it runs).
 */
class OutboundUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('guidearr.outbound.allow_private', false);
        config()->set('guidearr.outbound.allow_hosts', []);
        config()->set('guidearr.outbound.max_redirects', 5);
    }

    public static function blockedAddresses(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/playlist.m3u'],
            'loopback by name' => ['http://localhost/playlist.m3u'],
            'loopback v6' => ['http://[::1]/playlist.m3u'],
            'v4-mapped loopback' => ['http://[::ffff:127.0.0.1]/playlist.m3u'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private 10/8' => ['http://10.0.0.5/x.m3u'],
            'private 172.16/12' => ['http://172.16.0.1/x.m3u'],
            'private 192.168/16' => ['http://192.168.3.60:8181/x.m3u'],
            'carrier-grade NAT' => ['http://100.64.0.1/x.m3u'],
            'IETF protocol block' => ['http://192.0.0.1/x.m3u'],
            'benchmark block' => ['http://198.18.0.1/x.m3u'],
            'unspecified' => ['http://0.0.0.0/x.m3u'],
            'non-default port' => ['http://127.0.0.1:3306/'],
        ];
    }

    #[DataProvider('blockedAddresses')]
    public function test_internal_addresses_are_refused(string $url): void
    {
        $this->assertFalse(OutboundUrl::allowed($url), "{$url} should not be fetchable");
        $this->assertNotNull(OutboundUrl::reason($url));
    }

    public function test_public_addresses_are_still_fetchable(): void
    {
        // The point is to block the internal network, not to break the actual product.
        foreach (['http://8.8.8.8/list.m3u', 'https://1.1.1.1/guide.xml', 'https://203.0.113.10:8080/x'] as $url) {
            $this->assertTrue(OutboundUrl::allowed($url), "{$url} should be fetchable");
            $this->assertNull(OutboundUrl::reason($url));
        }
    }

    public function test_only_http_and_https_are_fetchable(): void
    {
        foreach (['file:///etc/passwd', 'gopher://8.8.8.8/', 'ftp://8.8.8.8/x', 'dict://8.8.8.8:11211/'] as $url) {
            $this->assertFalse(OutboundUrl::allowed($url), "{$url} should not be fetchable");
        }
    }

    public function test_an_operator_can_allow_a_named_lan_provider_back(): void
    {
        $this->assertFalse(OutboundUrl::allowed('http://127.0.0.1/x.m3u'));

        config()->set('guidearr.outbound.allow_hosts', ['127.0.0.1']);
        $this->assertTrue(OutboundUrl::allowed('http://127.0.0.1/x.m3u'));

        // Only the named host, not private space generally.
        $this->assertFalse(OutboundUrl::allowed('http://10.0.0.5/x.m3u'));
    }

    public function test_the_range_check_can_be_turned_off_entirely(): void
    {
        config()->set('guidearr.outbound.allow_private', true);

        $this->assertTrue(OutboundUrl::allowed('http://10.0.0.5/x.m3u'));

        // Still not a way to reach other schemes.
        $this->assertFalse(OutboundUrl::allowed('file:///etc/passwd'));
    }

    public function test_a_redirect_to_an_internal_address_is_refused(): void
    {
        // The bypass that matters: an attacker owns the first hop, so checking only the URL
        // that was typed in proves nothing.
        $this->expectException(\RuntimeException::class);

        OutboundUrl::nextHop('http://169.254.169.254/latest/meta-data/', 'https://8.8.8.8/list.m3u');
    }

    public function test_a_relative_redirect_is_resolved_against_the_url_it_came_from(): void
    {
        $this->assertSame(
            'https://8.8.8.8/new.m3u',
            OutboundUrl::nextHop('/new.m3u', 'https://8.8.8.8/old/list.m3u'),
        );

        $this->assertSame(
            'https://8.8.8.8/old/other.m3u',
            OutboundUrl::nextHop('other.m3u', 'https://8.8.8.8/old/list.m3u'),
        );

        // Protocol-relative keeps the scheme it came from rather than dropping to http.
        $this->assertSame(
            'https://1.1.1.1/x.m3u',
            OutboundUrl::nextHop('//1.1.1.1/x.m3u', 'https://8.8.8.8/list.m3u'),
        );
    }

    public function test_curl_is_not_allowed_to_follow_redirects_by_itself(): void
    {
        // Following in libcurl puts the request on the wire before anything can object, so
        // every caller follows by hand through execFollowing() instead.
        $opts = OutboundUrl::curlOptions();

        $this->assertFalse($opts[CURLOPT_FOLLOWLOCATION], 'curl must not follow redirects unchecked');
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $opts[CURLOPT_PROTOCOLS]);
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $opts[CURLOPT_REDIR_PROTOCOLS]);
    }

    public function test_every_fetcher_routes_through_the_guard(): void
    {
        // A new fetch path that forgets this is the whole vulnerability again, so pin the
        // three that exist rather than trusting the next person to remember.
        $files = [
            'app/Services/ProviderValidator.php',
            'app/Services/M3uDownloader.php',
            'app/Services/XtreamImporter.php',
        ];

        foreach ($files as $file) {
            $src = (string) file_get_contents(base_path($file));

            $this->assertStringContainsString(
                'OutboundUrl',
                $src,
                "{$file} fetches user-supplied URLs and must check them",
            );

            $this->assertStringNotContainsString(
                'CURLOPT_FOLLOWLOCATION => true',
                $src,
                "{$file} must not let curl follow redirects unchecked",
            );
        }
    }
}
