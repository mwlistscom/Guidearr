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
    private const SCHEMA_VERSION = 2;
    private const EDITABLE = ['name', 'tvg_id', 'tvg_name', 'tvg_logo', 'group_title', 'type', 'url'];

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
            version TEXT,
            error INTEGER DEFAULT 0,
            UNIQUE(group_title)
        )');

        $ver = (int) $this->db->query('PRAGMA user_version')->fetchColumn();
        $hasChannels = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='channels'")->fetchColumn();

        if (! $hasChannels) {
            $this->db->exec(self::CHANNELS_DDL);
        } elseif ($ver < self::SCHEMA_VERSION) {
            // Rebuild channels keyed on URL (was url+name), keeping the latest row per URL.
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

    /** Bump miss-count on rows not seen this run; delete after >3 misses. Never sweeps manual ('user') channels. Returns channels removed. */
    public function sweep(string $version): int
    {
        $this->db->prepare('UPDATE groups SET error=error+1 WHERE version IS NULL OR version<>:v')->execute([':v' => $version]);
        $this->db->prepare('UPDATE groups SET error=0 WHERE version=:v')->execute([':v' => $version]);
        $this->db->exec('DELETE FROM groups WHERE error>3');

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
            'SELECT g.id, g.group_title, g.position_order,
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
