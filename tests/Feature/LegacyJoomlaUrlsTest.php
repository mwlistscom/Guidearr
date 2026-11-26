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
 * counts the same shape at 410. Both halves are pinned here, against the same URLs.
 *
 * The match is any `option=` on `/` or `/index.php`, not only `option=com_`: the
 * first version pinned the value, and SQL/template-injection probes that put the
 * payload straight into option= (alongside Joomla's view=/Itemid=) walked past it
 * to a 200 from Laravel, 53 of them in the rule's first two weeks.
 */
class LegacyJoomlaUrlsTest extends TestCase
{
    private function nginx(): string
    {
        return (string) file_get_contents(base_path('docker/nginx.conf'));
    }

    /** The `if ($request_uri ~* "…") { rewrite ^ <target> last; }` rule: [regex, target]. */
    private function rule(): array
    {
        preg_match('/if \(\$request_uri ~\* "([^"]+)"\)\s*\{\s*rewrite \^ (\S+) last;\s*\}/', $this->nginx(), $m);

        $this->assertNotEmpty($m, 'docker/nginx.conf has no `if ($request_uri ~* "…") { rewrite ^ /… last; }` rule');

        return [$m[1], $m[2]];
    }

    /** The regex nginx applies to $request_uri, lifted from docker/nginx.conf. */
    private function nginxRule(): string
    {
        // nginx's ~* is a case-insensitive PCRE match; the same engine PHP uses.
        return '#'.str_replace('#', '\#', $this->rule()[0]).'#i';
    }

    /** The body of the `location = <target> {` block the rule rewrites to. */
    private function targetLocation(): string
    {
        [, $target] = $this->rule();
        preg_match('/location = '.preg_quote($target, '/').' \{(.*?)\n    \}/s', $this->nginx(), $m);

        $this->assertNotEmpty($m, "docker/nginx.conf has no `location = {$target}` block for the rule to land in");

        return $m[1];
    }

    public function test_the_410_is_answered_from_a_rate_limited_location_not_a_return(): void
    {
        // A `return 410` runs in the rewrite phase, before limit_req (preaccess) sees the
        // request, so a scanner could replay Joomla URLs without limit: 2,392 in two minutes from
        // one address, not one 429. The rewrite lands in a location that applies the same per-IP
        // budget as PHP and answers through try_files, which runs after limit_req.
        $loc = $this->targetLocation();
        $php = (string) preg_replace('/^.*?location ~ \\\.php\$ \{(.*?)\n    \}.*$/s', '$1', $this->nginx());

        preg_match('/^\s*limit_req zone=php\b[^;]*;/m', $php, $phpLimit);
        $this->assertNotEmpty($phpLimit, 'the PHP location has no limit_req zone=php line to mirror');
        $this->assertStringContainsString(trim($phpLimit[0]), $loc, 'the Joomla location must apply exactly the PHP location\'s limit_req');
        $this->assertMatchesRegularExpression('/^\s*limit_req_status 429;/m', $loc);
        $this->assertMatchesRegularExpression('/^\s*try_files \S+ =410;/m', $loc, 'answer via try_files (runs after limit_req), not return');
        $this->assertDoesNotMatchRegularExpression('/^\s*return\b/m', $loc, 'a `return` in the location skips limit_req just like one in the if');
        $this->assertMatchesRegularExpression('/^\s*internal;/m', $loc, 'only the rewrite may reach it');
        $this->assertDoesNotMatchRegularExpression('/return 410/', $this->nginx(), 'no `return 410` anywhere: it would be a limit_req bypass');
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
            // Seen live 2026-11-11..25: the payload is the option= value itself.
            'sqli in option' => ['/index.php?option=1%27%20AND%201=1%20UNION%20SELECT%20NULL--%20-&view=article&id=1%3Aprivacy-policy&catid=2&Itemid=140'],
            'boolean sqli, root path' => ['/?option=%27%29%20AND%20%28%27ajyaoi%27%3D%27ajyaoi%27%27&view=category&id=13&Itemid=157'],
            'ssti in option, not first' => ['/index.php?Itemid=162&catid=13&option=%24%7B%40var_dump%28md5%28400989603%29%29%7D%3B&view=article'],
            'empty option' => ['/index.php?option=&view=article'],
            // The SQLi variant appends an apostrophe to the path itself (seen live from 62.83.47.218).
            'encoded apostrophe after index.php' => ['/index.php%27?option=com_content&view=category&id=2&Itemid=140'],
            'encoded apostrophe after root' => ['/%27?option=com_content&view=category&id=13&Itemid=157'],
            'raw apostrophe after index.php' => ["/index.php'?option=com_content&view=category&id=2&Itemid=140"],
            'raw apostrophe after root' => ["/'?option=com_content&view=category&id=13&Itemid=157"],
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
            'option on a guidearr route' => ['/playlists/9?option=list'],
            'option as a value fragment on the root' => ['/?page=2&sort=caption'],
            'option as a suffix of another name' => ['/index.php?suboption=1'],
            'apostrophe on another path' => ['/providers%27?option=com_x'],
            'apostrophe without option' => ['/index.php%27?view=article'],
            'two apostrophes' => ['/index.php%27%27?option=com_x'],
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

        // And only on the two Joomla paths: a future Guidearr route that answers 410 for its own
        // reasons must not list whoever asked for it, even with an `option` parameter of its own.
        $this->assertFalse(ThreatFeed::isHostile('/playlists/9?option=list', 410));
        $this->assertFalse(ThreatFeed::isHostile('/providers?option=com_x', 410));
        $this->assertFalse(ThreatFeed::isHostile('/retired-route', 410));
    }
}
