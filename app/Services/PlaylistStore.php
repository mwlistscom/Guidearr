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

        // Provider data can reference group titles that aren't in its groups table.
        // Ensure every channel's group exists so nothing is orphaned in the editor.
        $missing = $this->db->query(
            'SELECT DISTINCT c.group_title FROM playlist_channels c
             LEFT JOIN playlist_groups g ON g.group_title = c.group_title
             WHERE g.group_title IS NULL AND c.group_title IS NOT NULL'
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($missing) {
            $this->begin();
            $o = $this->nextGroupOrder();
            foreach ($missing as $t) { $gIns->execute([':t' => $t, ':o' => $o]); $o += self::STEP; $groupsAdded++; }
            $this->commit();
        }

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

    public function groups(bool $includeDeleted = false): array
    {
        $where = $includeDeleted ? '' : 'WHERE g.deleted = 0';

        return $this->db->query(
            "SELECT g.id, g.group_title, g.position_order, g.enabled, g.deleted,
                    (SELECT COUNT(*) FROM playlist_channels c WHERE c.group_title = g.group_title AND c.deleted = 0) AS channels
             FROM playlist_groups g $where
             ORDER BY g.position_order, g.group_title"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Set a group flag (enabled|deleted) AND cascade the same flag to every channel in the group. */
    public function setGroupFlagCascade(int $id, string $field, bool $on): bool
    {
        if (! in_array($field, ['enabled', 'deleted'], true)) { return false; }
        $cur = $this->db->prepare('SELECT group_title FROM playlist_groups WHERE id = ?');
        $cur->execute([$id]);
        $title = $cur->fetchColumn();
        if ($title === false) { return false; }

        $this->begin();
        $this->db->prepare("UPDATE playlist_groups SET {$field} = ? WHERE id = ?")->execute([$on ? 1 : 0, $id]);
        $this->db->prepare("UPDATE playlist_channels SET {$field} = ? WHERE group_title = ?")->execute([$on ? 1 : 0, $title]);
        $this->commit();

        return $on;
    }

    /** Create a new (empty) group at the end of the order. Returns its id (or an existing match). */
    public function addGroup(string $title): int
    {
        $title = trim($title);
        if ($title === '') { return 0; }
        $ex = $this->db->prepare('SELECT id FROM playlist_groups WHERE group_title = ?');
        $ex->execute([$title]);
        if ($id = $ex->fetchColumn()) {
            $this->db->prepare('UPDATE playlist_groups SET deleted = 0 WHERE id = ?')->execute([$id]); // un-delete if it was hidden
            return (int) $id;
        }
        $o = $this->nextGroupOrder();
        $this->db->prepare('INSERT INTO playlist_groups (group_title, position_order) VALUES (?, ?)')->execute([$title, $o]);

        return (int) $this->db->lastInsertId();
    }

    // ---- editor: channel listing (global order via group join) ----

    private function channelWhere(?string $search, ?string $group, string $mode): array
    {
        $w = [];
        if ($mode === 'hide') { $w[] = 'c.deleted = 0'; }   // 'all' = no deleted filter
        $b = [];
        if ($group !== null && $group !== '') { $w[] = 'c.group_title = :g'; $b[':g'] = $group; }
        if ($search !== null && $search !== '') {
            $w[] = '(c.name LIKE :s OR c.group_title LIKE :s OR c.tvg_id LIKE :s OR c.tvg_name LIKE :s)';
            $b[':s'] = '%' . $search . '%';
        }

        return [$w ? ('WHERE ' . implode(' AND ', $w)) : '', $b];
    }

    public function channelCount(?string $search = null, ?string $group = null, string $mode = 'hide'): int
    {
        [$where, $bind] = $this->channelWhere($search, $group, $mode);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM playlist_channels c $where");
        $stmt->execute($bind);

        return (int) $stmt->fetchColumn();
    }

    /** Returns raw pointer rows in global order; the controller hydrates provider channel data. */
    public function channels(int $limit, int $offset, ?string $search = null, ?string $group = null, string $mode = 'hide'): array
    {
        [$where, $bind] = $this->channelWhere($search, $group, $mode);
        $stmt = $this->db->prepare(
            "SELECT c.id, c.provider_id, c.channel_id, c.group_title, c.position_order, c.enabled, c.deleted,
                    c.name, c.url, c.tvg_id, c.tvg_logo, c.tvg_name
             FROM playlist_channels c LEFT JOIN playlist_groups g ON g.group_title = c.group_title
             $where ORDER BY COALESCE(g.position_order, 1e12), c.position_order, c.id LIMIT :lim OFFSET :off"
        );
        foreach ($bind as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** All enabled, non-deleted pointer rows in global (group then position) order — for the public serving endpoints. */
    public function allForServe(): array
    {
        $stmt = $this->db->query(
            "SELECT c.id, c.provider_id, c.channel_id, c.group_title,
                    c.name, c.url, c.tvg_id, c.tvg_logo, c.tvg_name
             FROM playlist_channels c LEFT JOIN playlist_groups g ON g.group_title = c.group_title
             WHERE c.enabled = 1 AND c.deleted = 0
             ORDER BY COALESCE(g.position_order, 1e12), c.position_order, c.id"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---- editor: ordering (midpoint, place after the row above the target) ----

    /** Move a channel so it becomes global row N (1-based), inheriting the group of the row above. */
    public function moveChannelToRow(int $id, int $row): void
    {
        $cur = $this->db->prepare('SELECT group_title FROM playlist_channels WHERE id = ?');
        $cur->execute([$id]);
        $curGroup = $cur->fetchColumn();
        if ($curGroup === false) { return; }

        $others = $this->db->prepare(
            'SELECT c.id, c.group_title gt, c.position_order po
             FROM playlist_channels c LEFT JOIN playlist_groups g ON g.group_title = c.group_title
             WHERE c.id != :id AND c.deleted = 0 ORDER BY COALESCE(g.position_order, 1e12), c.position_order, c.id'
        );
        $others->execute([':id' => $id]);
        $rows = $others->fetchAll(PDO::FETCH_ASSOC);

        $newGroup = $curGroup;
        $newPos = self::STEP;

        if (! $rows) {
            // only channel — leave it in its group at the base
        } elseif ($row <= 1) {
            $newGroup = $rows[0]['gt'];
            $newPos = (float) $rows[0]['po'] / 2.0;
        } else {
            $ai = min($row - 2, count($rows) - 1);   // anchor = the row the channel should follow
            $anchor = $rows[$ai];
            $newGroup = $anchor['gt'];
            $next = null;
            for ($j = $ai + 1; $j < count($rows); $j++) {
                if ($rows[$j]['gt'] === $newGroup) { $next = $rows[$j]; break; }
            }
            $newPos = $next
                ? (((float) $anchor['po'] + (float) $next['po']) / 2.0)
                : ((float) $anchor['po'] + self::STEP);
        }

        $u = $this->db->prepare('UPDATE playlist_channels SET group_title = :g, position_order = :p WHERE id = :id');
        $u->execute([':g' => $newGroup, ':p' => $newPos, ':id' => $id]);
    }

    /** Move a group so it becomes row N (1-based) among non-deleted groups. */
    public function moveGroupToRow(int $id, int $row): void
    {
        $others = $this->db->prepare('SELECT id, position_order po FROM playlist_groups WHERE id != ? AND deleted = 0 ORDER BY position_order');
        $others->execute([$id]);
        $rows = $others->fetchAll(PDO::FETCH_ASSOC);
        if (! $rows) { return; }

        if ($row <= 1) {
            $newPos = (float) $rows[0]['po'] / 2.0;
        } else {
            $ai = min($row - 2, count($rows) - 1);
            $anchor = (float) $rows[$ai]['po'];
            $next = isset($rows[$ai + 1]) ? (float) $rows[$ai + 1]['po'] : null;
            $newPos = $next !== null ? (($anchor + $next) / 2.0) : ($anchor + self::STEP);
        }
        $this->db->prepare('UPDATE playlist_groups SET position_order = ? WHERE id = ?')->execute([$newPos, $id]);
    }

    // ---- editor: flags + manual add + edit ----

    public function setChannelFlag(int $id, string $field, bool $on): void
    {
        if (! in_array($field, ['enabled', 'deleted'], true)) { return; }
        $this->db->prepare("UPDATE playlist_channels SET {$field} = ? WHERE id = ?")->execute([$on ? 1 : 0, $id]);
    }

    public function setGroupFlag(int $id, string $field, bool $on): void
    {
        if (! in_array($field, ['enabled', 'deleted'], true)) { return; }
        $this->db->prepare("UPDATE playlist_groups SET {$field} = ? WHERE id = ?")->execute([$on ? 1 : 0, $id]);
    }

    /** Rename a group and cascade the new title onto its channels (they key on group_title). */
    public function renameGroup(int $id, string $newTitle): bool
    {
        $newTitle = trim($newTitle);
        if ($newTitle === '') { return false; }
        $cur = $this->db->prepare('SELECT group_title FROM playlist_groups WHERE id = ?');
        $cur->execute([$id]);
        $old = $cur->fetchColumn();
        if ($old === false || $old === $newTitle) { return false; }
        $ex = $this->db->prepare('SELECT COUNT(*) FROM playlist_groups WHERE group_title = ? AND id != ?');
        $ex->execute([$newTitle, $id]);
        if ((int) $ex->fetchColumn() > 0) { return false; }   // target title already used

        $this->begin();
        $this->db->prepare('UPDATE playlist_groups SET group_title = ? WHERE id = ?')->execute([$newTitle, $id]);
        $this->db->prepare('UPDATE playlist_channels SET group_title = ? WHERE group_title = ?')->execute([$newTitle, $old]);
        $this->commit();

        return true;
    }

    public function updateChannel(int $id, array $fields): void
    {
        $allowed = ['group_title', 'name', 'url', 'tvg_id', 'tvg_logo', 'tvg_name'];
        $set = [];
        $bind = [':id' => $id];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) { $set[] = "$k = :$k"; $bind[":$k"] = $v; }
        }
        if (! $set) { return; }
        $this->db->prepare('UPDATE playlist_channels SET ' . implode(',', $set) . ' WHERE id = :id')->execute($bind);
    }

    public function addManualChannel(array $c): int
    {
        $group = $c['group'] ?? '[Dummy]';
        $max = $this->db->prepare('SELECT COALESCE(MAX(position_order),0) FROM playlist_channels WHERE group_title = ?');
        $max->execute([$group]);
        $pos = (float) $max->fetchColumn() + self::STEP;

        $stmt = $this->db->prepare(
            'INSERT INTO playlist_channels (provider_id, channel_id, group_title, position_order, name, url, tvg_id, tvg_logo, tvg_name)
             VALUES (0,0,:g,:o,:n,:u,:ti,:tl,:tn)'
        );
        $stmt->execute([
            ':g' => $group, ':o' => $pos, ':n' => $c['name'] ?? '', ':u' => $c['url'] ?? '',
            ':ti' => $c['tvg_id'] ?? '', ':tl' => $c['tvg_logo'] ?? '', ':tn' => $c['tvg_name'] ?? ($c['name'] ?? ''),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function groupTitles(): array
    {
        return $this->db->query('SELECT group_title FROM playlist_groups WHERE deleted = 0 ORDER BY position_order')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Renumber group position_order to clean increments (10,20,30…) preserving current order. */
    public function reindexGroups(): int
    {
        $ids = $this->db->query('SELECT id FROM playlist_groups ORDER BY position_order, id')->fetchAll(PDO::FETCH_COLUMN);
        $this->begin();
        $upd = $this->db->prepare('UPDATE playlist_groups SET position_order = ? WHERE id = ?');
        $pos = self::STEP;
        foreach ($ids as $id) { $upd->execute([$pos, $id]); $pos += self::STEP; }
        $this->commit();

        return count($ids);
    }

    /** Renumber channel position_order per group (reset to STEP each group) preserving current order. */
    public function reindexChannels(): int
    {
        $titles = $this->db->query('SELECT DISTINCT group_title FROM playlist_channels')->fetchAll(PDO::FETCH_COLUMN);
        $this->begin();
        $sel = $this->db->prepare('SELECT id FROM playlist_channels WHERE group_title = ? ORDER BY position_order, id');
        $upd = $this->db->prepare('UPDATE playlist_channels SET position_order = ? WHERE id = ?');
        $n = 0;
        foreach ($titles as $t) {
            $sel->execute([$t]);
            $pos = self::STEP;
            foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $id) { $upd->execute([$pos, $id]); $pos += self::STEP; $n++; }
        }
        $this->commit();

        return $n;
    }
}
