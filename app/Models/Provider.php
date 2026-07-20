<?php

namespace App\Models;

use App\Services\ProviderStore;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
