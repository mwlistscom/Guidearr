<?php

namespace App\Services;

use PDO;

/**
 * Per-provider SQLite store for ingested channels/groups.
 * One file per provider at storage/app/feeds/provider_{id}.sqlite.
 * Versioned upsert + mark-and-sweep keeps row IDs stable across runs
 * (so future playlist mappings don't churn) while pruning stale rows.
 */
class ProviderStore
{
    private PDO $db;

    public function __construct(public int $providerId)
    {
        $dir = storage_path('app/feeds');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->db = new PDO('sqlite:' . $dir . '/provider_' . $providerId . '.sqlite');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->migrate();
    }

    public static function path(int $providerId): string
    {
        return storage_path('app/feeds/provider_' . $providerId . '.sqlite');
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
        $this->db->exec('CREATE TABLE IF NOT EXISTS channels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tvg_id TEXT, tvg_name TEXT, tvg_logo TEXT,
            group_title TEXT, name TEXT, url TEXT NOT NULL,
            type TEXT DEFAULT \'Live\', ext TEXT,
            version TEXT, error INTEGER DEFAULT 0,
            UNIQUE(url, name)
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS channels_group ON channels(group_title)');
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

    private ?\PDOStatement $channelStmt = null;
    private ?\PDOStatement $groupStmt = null;

    public function upsertChannel(array $c, string $version): void
    {
        $this->channelStmt ??= $this->db->prepare(
            'INSERT INTO channels (tvg_id,tvg_name,tvg_logo,group_title,name,url,type,ext,version,error)
             VALUES (:tvg_id,:tvg_name,:tvg_logo,:group_title,:name,:url,:type,:ext,:version,0)
             ON CONFLICT(url,name) DO UPDATE SET
               tvg_id=excluded.tvg_id, tvg_name=excluded.tvg_name, tvg_logo=excluded.tvg_logo,
               group_title=excluded.group_title, type=excluded.type, ext=excluded.ext,
               version=excluded.version, error=0'
        );
        $this->channelStmt->execute([
            ':tvg_id' => $c['tvg_id'] ?? '', ':tvg_name' => $c['tvg_name'] ?? '',
            ':tvg_logo' => $c['tvg_logo'] ?? '', ':group_title' => $c['group'] ?? '[Dummy]',
            ':name' => $c['name'] ?? '', ':url' => $c['url'] ?? '',
            ':type' => $c['type'] ?? 'Live', ':ext' => $c['ext'] ?? '', ':version' => $version,
        ]);
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

    /** Bump miss-count on rows not seen this run; delete after >3 misses. */
    public function sweep(string $version): void
    {
        foreach (['channels', 'groups'] as $t) {
            $this->db->prepare("UPDATE {$t} SET error=error+1 WHERE version IS NULL OR version<>:v")
                ->execute([':v' => $version]);
            $this->db->prepare("UPDATE {$t} SET error=0 WHERE version=:v")->execute([':v' => $version]);
            $this->db->exec("DELETE FROM {$t} WHERE error>3");
        }
    }

    public function counts(): array
    {
        return [
            'channels' => (int) $this->db->query('SELECT COUNT(*) FROM channels')->fetchColumn(),
            'groups'   => (int) $this->db->query('SELECT COUNT(*) FROM groups')->fetchColumn(),
        ];
    }
}
