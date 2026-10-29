<?php

namespace Tests\Feature;

use App\Support\Settings;
use App\Support\ThreatFeed;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The feed must not undo itself. A blocked scanner stops writing log lines, the
 * size-based log trim removes its old ones, and a feed built from the log alone then
 * drops it, so the firewall's next download unblocks it. Listed addresses are
 * remembered between rebuilds for window_days after their last attack.
 */
class ThreatFeedMemoryTest extends TestCase
{
    use RefreshDatabase;

    private const SCANNER = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        @mkdir(storage_path('logs'), 0775, true);
    }

    protected function tearDown(): void
    {
        $this->clean();

        parent::tearDown();
    }

    private function clean(): void
    {
        foreach ([ThreatFeed::path(), ThreatFeed::statePath(), $this->log()] as $f) {
            @unlink($f);
        }
    }

    private function log(): string
    {
        return storage_path('logs/nginx-access.log');
    }

    /** Write the access log afresh: $n hostile probes from $ip, stamped now. */
    private function writeLog(string $ip, int $n, string $path = '/.env', int $status = 403): void
    {
        $when = now()->format('d/M/Y:H:i:s O');
        $line = sprintf('%s - - [%s] "GET %s HTTP/1.1" %d 146 "-" "scanner" "%s"'."\n", $ip, $when, $path, $status, $ip);

        file_put_contents($this->log(), str_repeat($line, $n));
    }

    /** @return list<string> */
    private function listed(): array
    {
        return file(ThreatFeed::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }

    private function tally(array $hits = [], array $protected = []): array
    {
        return ['hits' => $hits, 'last' => [], 'protected' => $protected, 'scanned' => 0];
    }

    public function test_a_listed_address_survives_the_log_being_trimmed(): void
    {
        $this->writeLog(self::SCANNER, 25);
        ThreatFeed::rebuild();
        $this->assertSame([self::SCANNER], $this->listed());

        // Blocked at the firewall: it writes nothing more, and the trim removes what it wrote.
        file_put_contents($this->log(), '');
        $this->travel(3)->days();
        $stats = ThreatFeed::rebuild();

        $this->assertSame([self::SCANNER], $this->listed());
        $this->assertSame(1, $stats['held']);
    }

    public function test_a_remembered_address_drops_off_once_the_window_passes_after_its_last_attack(): void
    {
        $this->writeLog(self::SCANNER, 25);
        ThreatFeed::rebuild();
        file_put_contents($this->log(), '');

        $this->travel(13)->days();
        ThreatFeed::rebuild();
        $this->assertSame([self::SCANNER], $this->listed(), 'still inside the 14 days');

        $this->travel(2)->days();
        ThreatFeed::rebuild();
        $this->assertSame([], $this->listed());
        $this->assertSame([], json_decode(file_get_contents(ThreatFeed::statePath()), true));
    }

    /** A return visit both below the threshold (remembered path) and above it (selected from the log). */
    public static function returnVisits(): array
    {
        return ['a few more probes' => [3], 'enough to qualify again' => [25]];
    }

    #[DataProvider('returnVisits')]
    public function test_the_hold_counts_from_the_last_attack_not_the_first_listing(int $again): void
    {
        $this->writeLog(self::SCANNER, 25);
        ThreatFeed::rebuild();

        // Attacks again ten days later; the trim has removed the first batch by then.
        $this->travel(10)->days();
        $this->writeLog(self::SCANNER, $again);
        ThreatFeed::rebuild();
        file_put_contents($this->log(), '');

        $this->travel(10)->days();
        ThreatFeed::rebuild();

        $this->assertSame([self::SCANNER], $this->listed(), '20 days after first listing, 10 after its last attack');
    }

    public function test_a_remembered_address_that_pulls_a_playlist_comes_off(): void
    {
        $out = ThreatFeed::remember([], $this->tally([], [self::SCANNER => true]),
            [self::SCANNER => ['hits' => 50, 'last' => time()]], new DateTimeImmutable, 14, 20, 5000);

        $this->assertSame([], $out['ips']);
        $this->assertSame([], $out['state']);
    }

    public function test_allowlist_non_routable_and_a_raised_threshold_apply_to_remembered_addresses(): void
    {
        $state = [
            self::SCANNER => ['hits' => 50, 'last' => time()],
            '198.51.100.7' => ['hits' => 21, 'last' => time()],
            '10.0.0.5' => ['hits' => 50, 'last' => time()],
            'not-an-ip' => ['hits' => 50, 'last' => time()],
            '192.0.2.1' => 'garbage',
        ];

        $out = ThreatFeed::remember([], $this->tally(), $state, new DateTimeImmutable, 14, 30, 5000, ['203.0.113.0/24']);

        $this->assertSame([], $out['ips']);
    }

    public function test_the_cap_applies_to_the_merged_list_most_hits_first(): void
    {
        $state = [
            '198.51.100.1' => ['hits' => 40, 'last' => time()],
            '198.51.100.2' => ['hits' => 90, 'last' => time()],
        ];

        $out = ThreatFeed::remember([self::SCANNER => 60], $this->tally([self::SCANNER => 60]), $state, new DateTimeImmutable, 14, 20, 2);

        $this->assertSame(['198.51.100.2' => 90, self::SCANNER => 60], $out['ips']);
        $this->assertSame(['198.51.100.2', self::SCANNER], array_keys($out['state']), 'the memory is capped too');
        $this->assertSame(1, $out['held']);
    }

    public function test_an_unreadable_memory_falls_back_to_the_logs(): void
    {
        file_put_contents(ThreatFeed::statePath(), '{not json');
        $this->writeLog(self::SCANNER, 25);

        ThreatFeed::rebuild();

        $this->assertSame([self::SCANNER], $this->listed());
        $this->assertArrayHasKey(self::SCANNER, json_decode(file_get_contents(ThreatFeed::statePath()), true));
    }

    public function test_an_address_new_to_the_logs_still_needs_the_threshold(): void
    {
        Settings::set('threat_feed_min_hits', 20);
        $this->writeLog(self::SCANNER, 19);

        ThreatFeed::rebuild();

        $this->assertSame([], $this->listed());
    }
}
