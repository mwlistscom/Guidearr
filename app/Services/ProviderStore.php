<?php

namespace App\Services;

use PDO;

/**
 * Per-provider SQLite store for ingested channels/groups.
 * One file per provider at storage/app/feeds/provider_{id}.sqlite.
 *
 * Identity = stream URL, so when a provider renames a channel or swaps its
 * logo the existing row is updated in place (not duplicated). Versioned
 * mark-and-sweep prunes rows missing for >3 runs, but never touches manual
 * ('user') entries the user added by hand.
 */
class ProviderStore
{
    private const SCHEMA_VERSION = 4;
    private const EDITABLE = ['name', 'tvg_id', 'tvg_name', 'tvg_logo', 'group_title', 'type', 'url'];

    /** Placeholder title that event/PPV providers stuff into otherwise-empty guide slots. */
    private const GUIDE_FILLER_TITLE = 'No EVENT Today';

    private const CHANNELS_DDL = 'CREATE TABLE channels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tvg_id TEXT, tvg_name TEXT, tvg_logo TEXT,
        group_title TEXT, name TEXT, url TEXT NOT NULL,
        type TEXT DEFAULT \'Live\', ext TEXT,
        version TEXT, error INTEGER DEFAULT 0,
        UNIQUE(url)
    )';

    private PDO $db;
    private int $lastRowid = 0;
    private int $added = 0;
    private ?\PDOStatement $channelStmt = null;
    private ?\PDOStatement $groupStmt = null;
    private ?\PDOStatement $guideChStmt = null;
    private ?\PDOStatement $guideProgStmt = null;
    private int $guideProgCount = 0;

    public function __construct(public int $providerId)
    {
        $dir  = storage_path('app/feeds');
        $path = self::path($providerId);
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @chmod($dir, 0777); // worker (root) and php-fpm (www-data) may differ
        $this->db = new PDO('sqlite:' . $path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        @chmod($path, 0666);
        $this->migrate();
        $this->lastRowid = (int) $this->db->query('SELECT COALESCE(MAX(id),0) FROM channels')->fetchColumn();
    }

    public static function path(int $providerId): string
    {
        return storage_path('app/feeds/provider_' . $providerId . '.sqlite');
    }

    /** Temp file the XMLTV guide is streamed to (one per provider, deleted after parse). */
    public static function xmltvPath(int $providerId): string
    {
        return storage_path('app/feeds/provider_' . $providerId . '.xmltv');
    }

    private static function guideChDdl(string $t): string
    {
        return "CREATE TABLE {$t} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tvg_id TEXT NOT NULL,
            display_name TEXT,
            icon TEXT,
            UNIQUE(tvg_id)
        )";
    }

    private static function guideDdl(string $t): string
    {
        // `descr` (not `desc`) avoids the SQLite reserved word.
        return "CREATE TABLE {$t} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tvg_id TEXT NOT NULL,
            start INTEGER, stop INTEGER, timeshift TEXT,
            title TEXT, sub_title TEXT, descr TEXT,
            category TEXT, episode_num TEXT, icon TEXT, year TEXT, rating TEXT, info TEXT,
            UNIQUE(start,stop,tvg_id)
        )";
    }

    /** Begin an atomic guide reload: build into _new tables, swap on commit. Low-memory + no read lock on the live tables. */
    public function guideReloadBegin(): void
    {
        $this->db->exec('DROP TABLE IF EXISTS guide_new');
        $this->db->exec('DROP TABLE IF EXISTS guide_channels_new');
        $this->db->exec(self::guideChDdl('guide_channels_new'));
        $this->db->exec(self::guideDdl('guide_new'));

        $this->guideChStmt = $this->db->prepare(
            'INSERT INTO guide_channels_new (tvg_id,display_name,icon) VALUES (:t,:n,:i)
             ON CONFLICT(tvg_id) DO UPDATE SET display_name=excluded.display_name, icon=excluded.icon'
        );
        $this->guideProgStmt = $this->db->prepare(
            'INSERT INTO guide_new (tvg_id,start,stop,timeshift,title,sub_title,descr,category,episode_num,icon,year,rating,info)
             VALUES (:tvg_id,:start,:stop,:timeshift,:title,:sub_title,:descr,:category,:episode_num,:icon,:year,:rating,:info)
             ON CONFLICT(start,stop,tvg_id) DO NOTHING'
        );
        $this->guideProgCount = 0;
        $this->db->beginTransaction();
    }

    public function guideChannel(string $tvgId, ?string $name, ?string $icon): void
    {
        if ($tvgId === '') {
            return;
        }
        $this->guideChStmt->execute([':t' => $tvgId, ':n' => $name ?? '', ':i' => $icon ?? '']);
    }

    public function guideProgramme(array $p): void
    {
        $this->guideProgStmt->execute([
            ':tvg_id' => $p['tvg_id'] ?? '', ':start' => (int) ($p['start'] ?? 0), ':stop' => (int) ($p['stop'] ?? 0),
            ':timeshift' => $p['timeshift'] ?? '+0000', ':title' => $p['title'] ?? '', ':sub_title' => $p['sub_title'] ?? '',
            ':descr' => $p['desc'] ?? '', ':category' => $p['category'] ?? '', ':episode_num' => $p['episode_num'] ?? '',
            ':icon' => $p['icon'] ?? '', ':year' => $p['year'] ?? '', ':rating' => $p['rating'] ?? '', ':info' => $p['info'] ?? null,
        ]);
        if ((++$this->guideProgCount % 2000) === 0) {
            $this->commit();
            usleep(20000);
            $this->db->beginTransaction();
        }
    }

    /** Finish the reload: commit pending rows, then instantly swap _new tables over the live ones. */
    public function guideReloadCommit(): array
    {
        $this->commit();

        $this->db->exec('BEGIN');
        $this->db->exec('DROP TABLE IF EXISTS guide');
        $this->db->exec('ALTER TABLE guide_new RENAME TO guide');
        $this->db->exec('DROP TABLE IF EXISTS guide_channels');
        $this->db->exec('ALTER TABLE guide_channels_new RENAME TO guide_channels');
        $this->db->exec('COMMIT');

        $this->db->exec('CREATE INDEX IF NOT EXISTS guide_tvg_start ON guide(tvg_id,start)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS guide_stop ON guide(stop)');

        $this->guideChStmt = null;
        $this->guideProgStmt = null;

        return $this->guideCounts();
    }

    public function guideCounts(): array
    {
        $has = fn (string $t) => (bool) $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $t . "'")->fetchColumn();

        return [
            'guide_channels' => $has('guide_channels') ? (int) $this->db->query('SELECT COUNT(*) FROM guide_channels')->fetchColumn() : 0,
            'programmes'     => $has('guide') ? (int) $this->db->query('SELECT COUNT(*) FROM guide')->fetchColumn() : 0,
        ];
    }

    /**
     * Synthesize EPG entries for event/PPV channels that imported with NO programmes
     * but carry an upcoming event in their display-name, e.g.:
     *   "US (ESPN+ 008) | The Pat McAfee Show Jun 04 12:00PM ET (2026-06-04 12:00:05)"
     *   "CA (SN+ 012) | Toronto @ Atlanta (2026-06-04 18:30:00)"
     * The parenthesized ISO time is the start (US Eastern); the text after "|" is the title.
     * Runs on the LIVE guide tables after a reload commit.
     * @return array{examined:int,added:int} channels with no guide examined, and programmes added
     */
    /**
     * Synthesize EPG entries for event/PPV channels whose real schedule is unknown but
     * whose display-name carries the upcoming event, e.g.:
     *   "US (ESPN+ 021) | Milwaukee Brewers vs. Colorado Rockies Jun 05 8:00PM ET (2026-06-05 20:00:05)"
     *
     * Targets channels with NO *real* programmes — i.e. either zero programmes, or guide
     * rows that are entirely "No EVENT Today" filler. For those we drop the filler and
     * insert the real event. Channels that already carry a genuine schedule are left alone,
     * so we never clobber real guide data.
     *
     * The parenthesized ISO time is the start (US Eastern); the text after "|" is the title.
     * Runs on the LIVE guide tables after a reload commit.
     *
     * @return array{examined:int,added:int,cleared:int}
     *   examined = candidate channels (no real schedule, has a name)
     *   added    = real event programmes inserted
     *   cleared  = "No EVENT Today" filler rows removed
     */
    public function enhanceGuideFromChannelNames(int $defaultMinutes = 120): array
    {
        $empty = ['examined' => 0, 'added' => 0, 'cleared' => 0];
        if (! $this->hasTable('guide_channels') || ! $this->hasTable('guide')) {
            return $empty;
        }

        // Channels with no *real* programmes (none, or only filler) that have a display-name.
        $sel = $this->db->prepare(
            "SELECT gc.tvg_id AS tvg_id, gc.display_name AS display_name
               FROM guide_channels gc
              WHERE gc.display_name IS NOT NULL
                AND TRIM(gc.display_name) != ''
                AND NOT EXISTS (
                    SELECT 1 FROM guide g
                     WHERE g.tvg_id = gc.tvg_id AND g.title <> :filler
                )"
        );
        $sel->execute([':filler' => self::GUIDE_FILLER_TITLE]);
        $rows = $sel->fetchAll(\PDO::FETCH_ASSOC);

        if (! $rows) {
            return $empty;
        }

        $del = $this->db->prepare('DELETE FROM guide WHERE tvg_id = ? AND title = ?');
        $ins = $this->db->prepare(
            'INSERT OR IGNORE INTO guide (tvg_id,start,stop,timeshift,title) VALUES (?,?,?,?,?)'
        );

        $added = 0;
        $cleared = 0;
        $this->db->beginTransaction();
        try {
            foreach ($rows as $r) {
                $p = self::parseEmbeddedEvent((string) $r['display_name'], $defaultMinutes);
                if ($p === null) {
                    // Unparseable (sentinel/no event) — leave any filler in place.
                    continue;
                }
                // Replace the filler for this channel with the real event.
                $del->execute([$r['tvg_id'], self::GUIDE_FILLER_TITLE]);
                $cleared += $del->rowCount();
                $ins->execute([$r['tvg_id'], $p['start'], $p['stop'], '+0000', $p['title']]);
                if ($ins->rowCount() > 0) {
                    $added++;
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return ['examined' => count($rows), 'added' => $added, 'cleared' => $cleared];
    }

    /**
     * Parse one embedded-event display-name into a programme, or null if not convertible.
     * @return array{start:int,stop:int,title:string}|null
     */
    private static function parseEmbeddedEvent(string $name, int $defaultMinutes): ?array
    {
        // Must carry an ISO start time in parentheses.
        if (! preg_match('/\((\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\)/', $name, $m)) {
            return null;
        }
        // Skip far-future sentinel slots (e.g. 2098-12-31 = "no event scheduled").
        if ((int) $m[1] >= 2090) {
            return null;
        }

        // Title = text after the first "|", with the human time + the (ISO) stripped.
        $body = $name;
        if (($pos = strpos($body, '|')) !== false) {
            $body = substr($body, $pos + 1);
        }
        $body = preg_replace('/\(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\)/', '', $body);                 // (ISO)
        $body = preg_replace('/\s+[A-Z][a-z]{2} \d{1,2} \d{1,2}:\d{2}\s?(?:AM|PM) ET\b/i', '', $body); // "Jun 04 3:00AM ET"
        $body = preg_replace('/^\s*[A-Z][a-z]{2}_ \d{1,2}\/\d{1,2} _ /', '', trim((string) $body));   // "Thu_ 6/4 _ "
        $body = str_replace(['`', '_'], ["'", ' '], (string) $body);
        $title = trim((string) preg_replace('/\s+/', ' ', $body));
        if ($title === '') {
            return null;
        }

        // Embedded times are US Eastern; floor jitter seconds to :00.
        try {
            $start = (new \DateTime(
                sprintf('%s-%s-%s %s:%s:00', $m[1], $m[2], $m[3], $m[4], $m[5]),
                new \DateTimeZone('America/New_York')
            ))->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'start' => $start,
            'stop'  => $start + $defaultMinutes * 60,
            'title' => $title,
        ];
    }

    private function hasTable(string $t): bool
    {
        return (bool) $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $t . "'")->fetchColumn();
    }

    public function guideChannelCount(?string $search = null): int
    {
        if (! $this->hasTable('guide_channels')) { return 0; }
        $w = ''; $b = [];
        if ($search !== null && $search !== '') { $w = 'WHERE tvg_id LIKE :s OR display_name LIKE :s'; $b[':s'] = '%' . $search . '%'; }
        $st = $this->db->prepare("SELECT COUNT(*) FROM guide_channels $w");
        $st->execute($b);

        return (int) $st->fetchColumn();
    }

    public function guideChannelsPage(int $limit, int $offset, ?string $search = null): array
    {
        if (! $this->hasTable('guide_channels')) { return []; }
        $w = ''; $b = [];
        if ($search !== null && $search !== '') { $w = 'WHERE gc.tvg_id LIKE :s OR gc.display_name LIKE :s'; $b[':s'] = '%' . $search . '%'; }
        $st = $this->db->prepare(
            "SELECT gc.tvg_id, gc.display_name, gc.icon,
                    (SELECT COUNT(*) FROM guide g WHERE g.tvg_id = gc.tvg_id) AS programmes
             FROM guide_channels gc $w ORDER BY gc.display_name LIMIT :l OFFSET :o"
        );
        foreach ($b as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue(':l', $limit, PDO::PARAM_INT);
        $st->bindValue(':o', $offset, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function guideProgrammesFor(string $tvgId, int $fromTs, int $limit = 300): array
    {
        if (! $this->hasTable('guide')) { return []; }
        $st = $this->db->prepare(
            'SELECT start, stop, title, sub_title, descr, category, episode_num, icon, year, rating
             FROM guide WHERE tvg_id = :t AND stop >= :f ORDER BY start LIMIT :l'
        );
        $st->bindValue(':t', $tvgId);
        $st->bindValue(':f', $fromTs, PDO::PARAM_INT);
        $st->bindValue(':l', $limit, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Guide <channel> rows for a set of tvg-ids (chunked IN). */
    public function guideChannelsForIds(array $tvgIds): array
    {
        if (! $this->hasTable('guide_channels') || ! $tvgIds) { return []; }
        $tvgIds = array_values(array_unique(array_filter(array_map('strval', $tvgIds), fn ($v) => $v !== '')));
        $out = [];
        foreach (array_chunk($tvgIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->db->prepare("SELECT tvg_id, display_name, icon FROM guide_channels WHERE tvg_id IN ($in)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[] = $r; }
        }

        return $out;
    }

    /** Stream guide <programme> rows for a set of tvg-ids whose stop >= $fromTs, ordered by channel then start. */
    public function streamGuideProgrammesForIds(array $tvgIds, int $fromTs, callable $cb): void
    {
        if (! $this->hasTable('guide') || ! $tvgIds) { return; }
        $tvgIds = array_values(array_unique(array_filter(array_map('strval', $tvgIds), fn ($v) => $v !== '')));
        foreach (array_chunk($tvgIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->db->prepare(
                "SELECT tvg_id, start, stop, title, descr FROM guide WHERE tvg_id IN ($in) AND stop >= ? ORDER BY tvg_id, start"
            );
            $st->execute([...$chunk, $fromTs]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $cb($r); }
        }
    }

    /** Stream every channel (id + group + minimal data) ordered by group then id — used to seed playlists. */
    public function streamForSeed(callable $cb): void
    {
        $stmt = $this->db->query('SELECT id, group_title, name, url, tvg_id, tvg_logo, tvg_name FROM channels ORDER BY group_title, id');
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cb($r);
        }
    }

    /** Of the given channel ids, return those that still exist in this store (for playlist reconcile). */
    public function existingIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id FROM channels WHERE id IN ($in)");
        $stmt->execute($ids);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Fetch display data for a set of channel ids (id => row) — used to hydrate a playlist page. */
    public function channelsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id,tvg_id,tvg_name,tvg_logo,group_title,name,url,type FROM channels WHERE id IN ($in)");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = $r;
        }

        return $out;
    }

    public static function exists(int $providerId): bool
    {
        return is_file(self::path($providerId));
    }

    public static function channelCountFor(int $providerId): int
    {
        return self::exists($providerId) ? (new self($providerId))->counts()['channels'] : 0;
    }

    private function migrate(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_title TEXT NOT NULL,
            position_order INTEGER DEFAULT 0,
            type TEXT NOT NULL DEFAULT \'P\',
            version TEXT,
            error INTEGER DEFAULT 0,
            UNIQUE(group_title)
        )');

        // pre-existing stores: add groups.type if missing (manual groups marked 'user')
        $gcols = $this->db->query('PRAGMA table_info(groups)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (! in_array('type', $gcols, true)) {
            $this->db->exec("ALTER TABLE groups ADD COLUMN type TEXT NOT NULL DEFAULT 'P'");
        }

        $ver = (int) $this->db->query('PRAGMA user_version')->fetchColumn();
        $hasChannels = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='channels'")->fetchColumn();

        if (! $hasChannels) {
            $this->db->exec(self::CHANNELS_DDL);
        } elseif ($ver < 2) {
            // One-time rebuild: channels keyed on URL (was url+name), keeping the latest row per URL.
            $this->db->exec('BEGIN');
            $this->db->exec(str_replace('CREATE TABLE channels', 'CREATE TABLE channels_new', self::CHANNELS_DDL));
            $this->db->exec('INSERT OR IGNORE INTO channels_new
                (tvg_id,tvg_name,tvg_logo,group_title,name,url,type,ext,version,error)
                SELECT tvg_id,tvg_name,tvg_logo,group_title,name,url,type,ext,version,error
                FROM channels ORDER BY id DESC');
            $this->db->exec('DROP TABLE channels');
            $this->db->exec('ALTER TABLE channels_new RENAME TO channels');
            $this->db->exec('COMMIT');
        }

        // Guide tables (EPG). Empty for m3u providers; populated by the Xtream importer.
        $this->db->exec(str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', self::guideChDdl('guide_channels')));
        $this->db->exec(str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', self::guideDdl('guide')));
        $this->db->exec('CREATE INDEX IF NOT EXISTS guide_tvg_start ON guide(tvg_id,start)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS guide_stop ON guide(stop)');

        $this->db->exec('CREATE INDEX IF NOT EXISTS channels_group ON channels(group_title)');
        $this->db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    public function begin(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->commit();
        }
    }

    public function addedCount(): int
    {
        return $this->added;
    }

    public function upsertChannel(array $c, string $version): void
    {
        $this->channelStmt ??= $this->db->prepare(
            'INSERT INTO channels (tvg_id,tvg_name,tvg_logo,group_title,name,url,type,ext,version,error)
             VALUES (:tvg_id,:tvg_name,:tvg_logo,:group_title,:name,:url,:type,:ext,:version,0)
             ON CONFLICT(url) DO UPDATE SET
               tvg_id=excluded.tvg_id, tvg_name=excluded.tvg_name, tvg_logo=excluded.tvg_logo,
               group_title=excluded.group_title, name=excluded.name, ext=excluded.ext,
               version=excluded.version, error=0,
               type=CASE WHEN channels.type=\'user\' THEN \'user\' ELSE excluded.type END'
        );
        $this->channelStmt->execute([
            ':tvg_id' => $c['tvg_id'] ?? '', ':tvg_name' => $c['tvg_name'] ?? '',
            ':tvg_logo' => $c['tvg_logo'] ?? '', ':group_title' => $c['group'] ?? '[Dummy]',
            ':name' => $c['name'] ?? '', ':url' => $c['url'] ?? '',
            ':type' => $c['type'] ?? 'Live', ':ext' => $c['ext'] ?? '', ':version' => $version,
        ]);

        $id = (int) $this->db->lastInsertId();
        if ($id > $this->lastRowid) {   // last_insert_rowid only advances on a real INSERT
            $this->added++;
            $this->lastRowid = $id;
        }
    }

    /** Manually add (or revive) a channel; marked 'user' so refreshes never sweep it. */
    public function addChannel(array $c): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO channels (tvg_id,tvg_name,tvg_logo,group_title,name,url,type,ext,version,error)
             VALUES (:tvg_id,:tvg_name,:tvg_logo,:group_title,:name,:url,\'user\',\'\',NULL,0)
             ON CONFLICT(url) DO UPDATE SET name=excluded.name, group_title=excluded.group_title,
               tvg_name=excluded.tvg_name, tvg_logo=excluded.tvg_logo, type=\'user\''
        );
        $stmt->execute([
            ':tvg_id' => $c['tvg_id'] ?? '', ':tvg_name' => $c['tvg_name'] ?? ($c['name'] ?? ''),
            ':tvg_logo' => $c['tvg_logo'] ?? '', ':group_title' => $c['group'] ?? '[Dummy]',
            ':name' => $c['name'] ?? '', ':url' => $c['url'] ?? '',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function upsertGroup(string $title, int $order, string $version): void
    {
        $this->groupStmt ??= $this->db->prepare(
            'INSERT INTO groups (group_title,position_order,version,error)
             VALUES (:t,:o,:v,0)
             ON CONFLICT(group_title) DO UPDATE SET version=excluded.version, error=0'
        );
        $this->groupStmt->execute([':t' => $title, ':o' => $order, ':v' => $version]);
    }

    public function nextGroupOrder(): int
    {
        return (int) $this->db->query('SELECT COALESCE(MAX(position_order),0) FROM groups')->fetchColumn() + 10;
    }

    /** Manually add (or revive) a group; marked 'user' so refreshes never sweep it. */
    public function addGroup(string $title): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO groups (group_title,position_order,type,version,error)
             VALUES (:t,:o,\'user\',NULL,0)
             ON CONFLICT(group_title) DO UPDATE SET type=\'user\''
        );
        $stmt->execute([':t' => $title, ':o' => $this->nextGroupOrder()]);

        return (int) $this->db->lastInsertId();
    }

    /** Bump miss-count on rows not seen this run; delete after >3 misses. Never sweeps manual ('user') rows. Returns channels removed. */
    public function sweep(string $version): int
    {
        $this->db->prepare("UPDATE groups SET error=error+1 WHERE (version IS NULL OR version<>:v) AND type<>'user'")->execute([':v' => $version]);
        $this->db->prepare('UPDATE groups SET error=0 WHERE version=:v')->execute([':v' => $version]);
        $this->db->exec("DELETE FROM groups WHERE error>3 AND type<>'user'");

        $this->db->prepare("UPDATE channels SET error=error+1 WHERE (version IS NULL OR version<>:v) AND type<>'user'")->execute([':v' => $version]);
        $this->db->prepare('UPDATE channels SET error=0 WHERE version=:v')->execute([':v' => $version]);
        $del = $this->db->prepare("DELETE FROM channels WHERE error>3 AND type<>'user'");
        $del->execute();

        return $del->rowCount();
    }

    public function counts(): array
    {
        return [
            'channels' => (int) $this->db->query('SELECT COUNT(*) FROM channels')->fetchColumn(),
            'groups'   => (int) $this->db->query('SELECT COUNT(*) FROM groups')->fetchColumn(),
        ];
    }

    /** Groups with a live channel count, ordered for the right-hand pane / the Group dropdown. */
    public function groups(): array
    {
        return $this->db->query(
            'SELECT g.id, g.group_title, g.type, g.position_order,
                    (SELECT COUNT(*) FROM channels c WHERE c.group_title = g.group_title) AS channels
             FROM groups g ORDER BY g.position_order, g.group_title COLLATE NOCASE'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function channels(int $limit, int $offset, ?string $search = null, ?string $group = null): array
    {
        [$where, $bind] = $this->channelFilter($search, $group);
        $stmt = $this->db->prepare(
            "SELECT id,tvg_id,tvg_name,tvg_logo,group_title,name,url,type
             FROM channels {$where} ORDER BY id LIMIT :lim OFFSET :off"
        );
        foreach ($bind as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function channelCount(?string $search = null, ?string $group = null): int
    {
        [$where, $bind] = $this->channelFilter($search, $group);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM channels {$where}");
        $stmt->execute($bind);

        return (int) $stmt->fetchColumn();
    }

    /** Build a WHERE clause from an optional text search and an exact group match. */
    private function channelFilter(?string $search, ?string $group): array
    {
        $clauses = [];
        $bind    = [];
        if ($search !== null && $search !== '') {
            $clauses[] = '(name LIKE :s OR group_title LIKE :s OR tvg_name LIKE :s)';
            $bind[':s'] = '%' . $search . '%';
        }
        if ($group !== null && $group !== '') {
            $clauses[] = 'group_title = :g';
            $bind[':g'] = $group;
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $bind];
    }

    public function updateChannel(int $id, string $field, $value): bool
    {
        if (! in_array($field, self::EDITABLE, true)) {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE channels SET {$field} = :v WHERE id = :id");
        $stmt->execute([':v' => $value, ':id' => $id]);

        return true;
    }

    public function deleteChannel(int $id): void
    {
        $this->db->prepare('DELETE FROM channels WHERE id = :id')->execute([':id' => $id]);
    }
}
