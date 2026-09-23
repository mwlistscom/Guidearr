<?php

namespace App\Models;

use App\Services\ProviderStore;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Provider extends Model
{
    public const TYPES = ['xtream', 'm3u', 'xmltv', 'manual'];

    /**
     * last_status marker written when the cold-provider reaper disables a provider. It distinguishes
     * a "reaped for inactivity" disable (safe to auto-revive on next playlist access) from a disable
     * caused by repeated fetch failures or an admin action (which must stay disabled). Only
     * providers:reap-cold writes it, and only the markTouched()/touchAllForUser() paths (here and
     * on Playlist) act on it.
     */
    public const REAPED_STATUS = 'cold';

    protected $fillable = [
        'user_id', 'name', 'type', 'url', 'epg_url', 'username', 'password',
        'timeshift', 'myshift', 'enabled', 'enhance_guide', 'refresh_hour', 'refresh_minute',
        'last_refresh_at', 'last_status', 'last_touch_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enhance_guide' => 'boolean',
            'myshift' => 'integer',
            'refresh_hour' => 'integer',
            'refresh_minute' => 'integer',
            'last_refresh_at' => 'datetime',
            'last_touch_at' => 'datetime',
            'password' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Provider $p) {
            // default auto-refresh to a random time between 01:00 and 03:59 unless an hour was chosen
            if (empty($p->refresh_hour)) {
                $p->refresh_hour = random_int(1, 3);
            }
            // spread load across the hour: random minute unless explicitly set
            if (is_null($p->refresh_minute)) {
                $p->refresh_minute = random_int(0, 59);
            }
        });

        // Direct provider deletes (e.g. the grid trash button) clean up the per-provider
        // SQLite store file + any of its logs. feed_queue rows cascade via FK.
        // NOTE: a DB-level cascade (when a User is deleted) does NOT fire this event —
        // that path is handled by the purge queue snapshot in the User model.
        static::deleting(function (Provider $p) {
            $path = ProviderStore::path($p->id);
            foreach (['', '-wal', '-shm'] as $suffix) {
                if (is_file($path.$suffix)) {
                    @unlink($path.$suffix);
                }
            }
            FeedLog::where('provider_id', $p->id)->delete();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function feedQueue(): HasOne
    {
        return $this->hasOne(FeedQueue::class);
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'playlist_providers');
    }

    /**
     * What deleting this provider would do to its owner's playlists.
     *
     * Playlist channels are only POINTERS into a provider store, so removing a provider takes its
     * channels with it. Split the holders in two:
     *
     *  - 'deleted'  — playlists this provider is the ONLY content source for. Every pointer they
     *                 hold is into this store, so they would be left serving nothing at all; they
     *                 are deleted alongside it rather than left as a wall of "(missing channel)".
     *  - 'affected' — playlists another provider still feeds. They survive with their ordering,
     *                 renames and group flags intact and merely lose this provider's channels
     *                 (the same reconcile the refresh path already does), and/or their guide
     *                 source. Never delete one of these: the user's curation of the other
     *                 providers' channels is not this provider's to throw away.
     *
     * A playlist that uses this provider ONLY as its guide (EPG) source is always 'affected' —
     * losing an EPG is not losing the channels.
     */
    public function playlistDeleteImpact(): array
    {
        $holders = $this->playlists()
            ->orderBy('playlists.id')
            ->get(['playlists.id', 'playlists.name', 'playlists.guide_provider_id']);

        // How many OTHER providers each holder still has. A holder missing from this map has none.
        $others = DB::table('playlist_providers')
            ->whereIn('playlist_id', $holders->pluck('id'))
            ->where('provider_id', '!=', $this->id)
            ->groupBy('playlist_id')
            ->selectRaw('playlist_id, COUNT(*) as c')
            ->pluck('c', 'playlist_id');

        $deleted = [];
        $affected = [];

        foreach ($holders as $p) {
            $n = (int) ($others[$p->id] ?? 0);

            if ($n === 0) {
                $deleted[] = ['id' => (int) $p->id, 'name' => (string) $p->name];

                continue;
            }

            $affected[] = [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'others' => $n,
                'guide' => (int) $p->guide_provider_id === (int) $this->id,
            ];
        }

        // Playlists that reference this provider ONLY as their guide source — they hold no channel
        // pointers into it at all, so they just lose their EPG.
        $guideOnly = Playlist::where('user_id', $this->user_id)
            ->where('guide_provider_id', $this->id)
            ->whereNotIn('id', $holders->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($guideOnly as $p) {
            $affected[] = [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'others' => 0,
                'guide' => true,
            ];
        }

        return ['deleted' => $deleted, 'affected' => $affected];
    }

    public function feedLogs(): HasMany
    {
        return $this->hasMany(FeedLog::class);
    }

    /**
     * Update the "last touched" marker for a user action (edit OR view). Only real user activity
     * may call this — never the worker, scheduler or a migrator; the cold reaper's whole signal is
     * "has a human used this?". A provider the reaper disabled is revived here, since being looked
     * at means it is wanted again. Scoped to REAPED_STATUS so a fetch-failure or admin disable
     * (which must stay disabled) is never resurrected.
     */
    public function markTouched(): void
    {
        $fill = ['last_touch_at' => now()];
        if (! $this->enabled && $this->last_status === self::REAPED_STATUS) {
            $fill['enabled'] = true;
        }

        $this->forceFill($fill)->save();
    }

    /**
     * Bulk markTouched() for every provider a user owns — the signal behind "opened the providers
     * grid". This is what keeps a provider with NO playlist attached warm; its only other activity
     * would be an explicit edit. Rows touched more recently than $staleBefore are skipped so a
     * reloading grid doesn't write on every request. Returns the number of rows stamped.
     */
    public static function touchAllForUser(int $userId, ?CarbonInterface $staleBefore = null): int
    {
        $q = static::where('user_id', $userId);
        if ($staleBefore) {
            $q->where(fn ($w) => $w->whereNull('last_touch_at')->orWhere('last_touch_at', '<', $staleBefore));
        }

        $ids = $q->pluck('id')->all();
        if (! $ids) {
            return 0;
        }

        static::whereIn('id', $ids)->update(['last_touch_at' => now()]);
        static::whereIn('id', $ids)
            ->where('enabled', false)
            ->where('last_status', self::REAPED_STATUS)
            ->update(['enabled' => true]);

        return count($ids);
    }

    public function requiresUrl(): bool
    {
        return $this->type !== 'manual';
    }

    /** Safe representation for the grid (never exposes the password). */
    public function toGridArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'type' => $this->type,
            'enabled' => (bool) $this->enabled,
            'timeshift' => $this->timeshift,
            'myshift' => $this->myshift,
            'refresh_hour' => $this->refresh_hour,
            'last_status' => $this->last_status,
            'last_refresh_at' => optional($this->last_refresh_at)->format('Y-m-d H:i:s'),
            'last_touch_at' => optional($this->last_touch_at)->format('Y-m-d H:i:s'),
        ];
    }
}
