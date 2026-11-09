<?php

namespace Tests\Feature;

use App\Support\ThreatFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Bots replay URLs from the Joomla site that used to live on this domain
 * (/index.php?option=com_virtuemart&…). Laravel has no such route and answered
 * every one with the home page, a 200 that cost a PHP render and that the threat
 * feed could not count. nginx now answers them 410 Gone before PHP, and the feed
 * counts `option=com_` at 410. Both halves are pinned here, against the same URLs.
 */
class LegacyJoomlaUrlsTest extends TestCase
{
    /** The regex nginx applies to $request_uri, lifted from docker/nginx.conf. */
    private function nginxRule(): string
    {
        $conf = (string) file_get_contents(base_path('docker/nginx.conf'));

        preg_match('/if \(\$request_uri ~\* "([^"]+)"\)\s*\{\s*return 410;\s*\}/', $conf, $m);

        $this->assertNotEmpty($m, 'docker/nginx.conf has no `if ($request_uri ~* "…") { return 410; }` rule');

        // nginx's ~* is a case-insensitive PCRE match; the same engine PHP uses.
        return '#'.str_replace('#', '\#', $m[1]).'#i';
    }

    /** @return array<string, array{string}> */
    public static function joomlaUrls(): array
    {
        return [
            'virtuemart category' => ['/index.php?option=com_virtuemart&view=category&virtuemart_category_id=5'],
            'option not first' => ['/index.php?tmpl=component&option=com_virtuemart&view=productdetails'],
            'content article' => ['/index.php?Itemid=162&catid=13&id=15%3Atop-features-2&option=com_content&view=article'],
            'users' => ['/index.php?option=com_users&view=registration'],
            'bare root path' => ['/?Itemid=157&id=13&option=com_content&view=article'],
            'mixed case' => ['/INDEX.PHP?OPTION=COM_CONTENT'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function guidearrUrls(): array
    {
        return [
            'home' => ['/'],
            'front controller' => ['/index.php'],
            'playlist serve' => ['/m3u?key=abc123'],
            'guide serve' => ['/epg?key=abc123'],
            'login' => ['/login'],
            'paginated grid' => ['/?page=2'],
            'option in a value, not a name' => ['/m3u?key=abc&list=option=com_x'],
            'option on another path' => ['/providers?option=com_x'],
        ];
    }

    #[DataProvider('joomlaUrls')]
    public function test_nginx_answers_a_legacy_joomla_url_with_410_and_the_feed_counts_it(string $url): void
    {
        $this->assertSame(1, preg_match($this->nginxRule(), $url), "nginx would not 410 {$url}");
        $this->assertTrue(ThreatFeed::isHostile($url, 410), "feed does not count {$url} at 410");
    }

    #[DataProvider('guidearrUrls')]
    public function test_the_rule_leaves_guidearr_urls_alone(string $url): void
    {
        $this->assertSame(0, preg_match($this->nginxRule(), $url), "nginx would 410 {$url}");
        $this->assertFalse(ThreatFeed::isHostile($url, 200));
    }

    public function test_the_feed_only_counts_joomla_urls_at_the_status_nginx_gives_them(): void
    {
        // A 200 means Laravel served it, i.e. an install whose nginx.conf predates the rule.
        // Listing on that would charge a crawler following an old link for a page we served.
        $this->assertFalse(ThreatFeed::isHostile('/index.php?option=com_content&view=featured', 200));
        $this->assertFalse(ThreatFeed::isHostile('/?Itemid=157&option=com_content', 200));
        $this->assertFalse(ThreatFeed::isHostile('/?Itemid=157&option=com_content', 404));

        // And only Joomla component names: a future route that answers 410 for its own reasons
        // must not list whoever asked for it.
        $this->assertFalse(ThreatFeed::isHostile('/playlists/9?option=list', 410));
        $this->assertFalse(ThreatFeed::isHostile('/retired-route', 410));
    }
}
