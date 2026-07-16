<?php

namespace App\Models;

use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Playlist extends Model
{
    protected $fillable = [
        'user_id', 'name', 'iplock', 'cipher', 'channel_start',
        'extgrp_tags', 'guide_provider_id', 'enabled', 'last_touch_at',
    ];

    protected $casts = [
        'extgrp_tags' => 'boolean',
        'enabled' => 'boolean',
        'channel_start' => 'integer',
        'last_touch_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Playlist $p) {
            if (empty($p->cipher)) {
                $p->cipher = self::freshCipher();
            }
        });

        // Deleting a playlist drops its SQLite pointer file outright (instant) and lets the
        // playlist_providers pivot cascade. DB-cascade on user delete won't fire this — that's
        // handled the same way as providers (the editor/admin path deletes models directly).
        static::deleting(function (Playlist $p) {
            $path = PlaylistStore::path($p->id);
            foreach (['', '-wal', '-shm'] as $suffix) {
                if (is_file($path.$suffix)) {
                    @unlink($path.$suffix);
                }
            }
        });
    }

    /** Cryptographically-random URL key (Str::random uses random_bytes), unique across playlists. */
    public static function freshCipher(): string
    {
        do {
            $c = Str::random(12);
        } while (self::where('cipher', $c)->exists());

        return $c;
    }

    public function providers()
    {
        return $this->belongsToMany(Provider::class, 'playlist_providers');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function providerIds(): array
    {
        return $this->providers()->pluck('providers.id')->all();
    }

    /**
     * Record activity on this playlist: stamp its own last_touch_at and propagate the same
     * timestamp to every backing provider (content providers + the guide provider), so a provider
     * stays "warm" whenever its playlist is served OR edited. This is the signal the cold-provider
     * reaper keys off. saveQuietly so a touch never looks like a content edit / fires model events.
     */
    public function markTouched(?Carbon $at = null): void
    {
        $now = $at ?? now();
        $this->forceFill(['last_touch_at' => $now])->saveQuietly();

        $ids = $this->providers()->pluck('providers.id')->all();
        if ($this->guide_provider_id) {
            $ids[] = (int) $this->guide_provider_id;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids) {
            Provider::whereIn('id', $ids)->update(['last_touch_at' => $now]);

            // Revive providers the cold-reaper disabled — access means they're wanted again. Scoped
            // to REAPED_STATUS so a fetch-failure or admin disable is never resurrected into a loop.
            Provider::whereIn('id', $ids)
                ->where('enabled', false)
                ->where('last_status', Provider::REAPED_STATUS)
                ->update(['enabled' => true]);
        }
    }

    /**
     * Self-heal: rebuild the per-playlist channel store from its attached providers when the
     * store FILE is missing (e.g. the SQLite file was removed out-of-band). Deliberately triggers
     * ONLY on a missing file — an existing-but-empty store may be an intentional "delete all", so
     * it is never overwritten. The file is created only when at least one attached provider has
     * imported data to seed from; otherwise it is left absent so a later call can retry once the
     * provider feed arrives (never leaves an unseeded empty shell behind). Returns true if it reseeded.
     */
    public function ensureStoreSeeded(): bool
    {
        if (PlaylistStore::existsFor($this->id)) {
            return false; // present (even if empty) — never clobber deliberate edits
        }

        $seedable = array_values(array_filter(
            $this->providerIds(),
            fn ($pid) => ProviderStore::exists((int) $pid)
        ));
        if (! $seedable) {
            return false; // nothing to rebuild from yet — leave the store absent so a later serve retries
        }

        $store = new PlaylistStore($this->id);
        foreach ($seedable as $pid) {
            $store->seedFromProvider((int) $pid, new ProviderStore((int) $pid));
        }

        Log::warning('playlist store file was missing; auto-reseeded from providers', [
            'playlist_id' => $this->id,
            'cipher' => $this->cipher,
            'providers' => $seedable,
        ]);

        return true;
    }

    public function toGridArray(): array
    {
        $store = PlaylistStore::existsFor($this->id) ? new PlaylistStore($this->id) : null;
        $counts = $store ? $store->counts() : ['channels' => 0, 'groups' => 0];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'iplock' => $this->iplock,
            'cipher' => $this->cipher,
            'channel_start' => $this->channel_start,
            'extgrp_tags' => (bool) $this->extgrp_tags,
            'guide_provider_id' => $this->guide_provider_id,
            'enabled' => (bool) $this->enabled,
            'channels' => $counts['channels'],
            'groups' => $counts['groups'],
            'last_touch_at' => optional($this->last_touch_at)->format('Y-m-d H:i:s'),
        ];
    }
}
