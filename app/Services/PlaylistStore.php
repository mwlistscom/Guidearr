<?php

namespace App\Services;

use PDO;

/**
 * Per-playlist SQLite store at storage/app/playlists/playlist_{id}.sqlite.
 *
 * Holds only POINTERS + ordering, never channel data:
 *   - playlist_groups : merged-by-title groups with their own position_order (+ enabled/deleted)
 *   - playlist_channels: (provider_id, channel_id) pointers into a provider store, OR inline
 *     manual channels (provider_id=0). Each carries group_title + position_order + flags.
 *
 * Global order = playlist_groups.position_order, then playlist_channels.position_order
 * (resolved with a JOIN on group_title — no denormalised group_order to keep in sync).
 */
class PlaylistStore
{
    private const SCHEMA_VERSION = 1;
    private const STEP = 10.0;

    private PDO $db;

    public function __construct(public int $playlistId)
    {
        $dir = storage_path('app/playlists');
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @chmod($dir, 0777);
        $path = self::path($playlistId);
        $this->db = new PDO('sqlite:' . $path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        @chmod($path, 0666);
        $this->migrate();
    }

    public static function path(int $id): string
    {
        return storage_path('app/playlists/playlist_' . $id . '.sqlite');
    }

    public static function existsFor(int $id): bool
    {
        return is_file(self::path($id));
    }

    private function migrate(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS playlist_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_title TEXT NOT NULL,
            position_order REAL NOT NULL DEFAULT 0,
            enabled INTEGER NOT NULL DEFAULT 1,
            deleted INTEGER NOT NULL DEFAULT 0,
            UNIQUE(group_title)
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS playlist_channels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL DEFAULT 0,   -- 0 = manual
            channel_id INTEGER NOT NULL DEFAULT 0,    -- provider-store row id (0 = manual)
            group_title TEXT NOT NULL DEFAULT \'[Dummy]\',
            position_order REAL NOT NULL DEFAULT 0,
            enabled INTEGER NOT NULL DEFAULT 1,
            deleted INTEGER NOT NULL DEFAULT 0,
            name TEXT, url TEXT, tvg_id TEXT, tvg_logo TEXT, tvg_name TEXT  -- inline data for manual rows
        )');
        // Provider channels are unique per (provider, channel); manual rows (provider_id=0) are exempt.
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS pc_provchan ON playlist_channels(provider_id, channel_id) WHERE provider_id > 0');
        $this->db->exec('CREATE INDEX IF NOT EXISTS pc_group ON playlist_channels(group_title)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS pc_provider ON playlist_channels(provider_id)');
        $this->db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    public function begin(): void { $this->db->beginTransaction(); }
    public function commit(): void { if ($this->db->inTransaction()) { $this->db->commit(); } }

    public function counts(): array
    {
        return [
            'groups'   => (int) $this->db->query('SELECT COUNT(*) FROM playlist_groups WHERE deleted=0')->fetchColumn(),
            'channels' => (int) $this->db->query('SELECT COUNT(*) FROM playlist_channels WHERE deleted=0')->fetchColumn(),
        ];
    }

    private function nextGroupOrder(): float
    {
        return (float) $this->db->query('SELECT COALESCE(MAX(position_order),0) FROM playlist_groups')->fetchColumn() + self::STEP;
    }

    /**
     * Seed this playlist from a provider's store: merge groups by title (append new ones at the
     * end), then append the provider's channels as pointers into the right group. Idempotent for
     * channels via the (provider_id, channel_id) unique index.
     *
     * @return array{groups_added:int,channels_added:int}
     */
    public function seedFromProvider(int $providerId, ProviderStore $ps): array
    {
        $groupsAdded = 0;
        $channelsAdded = 0;

        // existing group titles + the per-group running max position_order
        $existingGroups = $this->db->query('SELECT group_title FROM playlist_groups')->fetchAll(PDO::FETCH_COLUMN);
        $existingGroups = array_flip($existingGroups);

        $this->begin();
        $gOrder = $this->nextGroupOrder();
        $gIns = $this->db->prepare('INSERT OR IGNORE INTO playlist_groups (group_title, position_order) VALUES (:t,:o)');
        foreach ($ps->groups() as $g) {
            $title = $g['group_title'] ?? '[Dummy]';
            if (! isset($existingGroups[$title])) {
                $gIns->execute([':t' => $title, ':o' => $gOrder]);
                $existingGroups[$title] = true;
                $gOrder += self::STEP;
                $groupsAdded++;
            }
        }
        $this->commit();

        // per-group running position_order (continue after whatever's already there)
        $maxPos = [];
        $rows = $this->db->query('SELECT group_title, MAX(position_order) m FROM playlist_channels GROUP BY group_title')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) { $maxPos[$r['group_title']] = (float) $r['m']; }

        $cIns = $this->db->prepare(
            'INSERT OR IGNORE INTO playlist_channels (provider_id, channel_id, group_title, position_order)
             VALUES (:p,:c,:g,:o)'
        );
        $n = 0;
        $this->begin();
        $ps->streamForSeed(function (array $row) use ($cIns, $providerId, &$maxPos, &$channelsAdded, &$n) {
            $g = $row['group_title'] ?: '[Dummy]';
            $pos = ($maxPos[$g] ?? 0.0) + self::STEP;
            $maxPos[$g] = $pos;
            $cIns->execute([':p' => $providerId, ':c' => (int) $row['id'], ':g' => $g, ':o' => $pos]);
            $channelsAdded += $cIns->rowCount() > 0 ? 1 : 0;
            if (++$n % 2000 === 0) { $this->commit(); usleep(20000); $this->begin(); }
        });
        $this->commit();

        return ['groups_added' => $groupsAdded, 'channels_added' => $channelsAdded];
    }

    /** Remove this provider's channels from the playlist (when the provider is detached). */
    public function removeProvider(int $providerId): int
    {
        $stmt = $this->db->prepare('DELETE FROM playlist_channels WHERE provider_id = ?');
        $stmt->execute([$providerId]);

        return $stmt->rowCount();
    }

    /**
     * Reconcile against a provider store: drop pointers whose channel no longer exists there
     * (the provider store already applied the 3-miss mark-sweep before a channel disappears).
     *
     * @return int removed pointer count
     */
    public function reconcileProvider(int $providerId, ProviderStore $ps): int
    {
        $ids = $this->db->prepare('SELECT channel_id FROM playlist_channels WHERE provider_id = ?');
        $ids->execute([$providerId]);
        $have = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if (! $have) {
            return 0;
        }
        $alive = $ps->existingIds($have);          // subset still present in the provider store
        $dead  = array_values(array_diff($have, $alive));
        if (! $dead) {
            return 0;
        }
        $this->begin();
        $del = $this->db->prepare('DELETE FROM playlist_channels WHERE provider_id = ? AND channel_id = ?');
        foreach ($dead as $cid) { $del->execute([$providerId, $cid]); }
        $this->commit();

        return count($dead);
    }

    public function groups(): array
    {
        return $this->db->query(
            'SELECT g.id, g.group_title, g.position_order, g.enabled, g.deleted,
                    (SELECT COUNT(*) FROM playlist_channels c WHERE c.group_title = g.group_title AND c.deleted = 0) AS channels
             FROM playlist_groups g WHERE g.deleted = 0
             ORDER BY g.position_order, g.group_title'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
