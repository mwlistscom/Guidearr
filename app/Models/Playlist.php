<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Playlist extends Model
{
    protected $fillable = [
        'user_id', 'name', 'iplock', 'cipher', 'channel_start',
        'extgrp_tags', 'guide_provider_id', 'enabled', 'last_touch_at',
    ];

    protected $casts = [
        'extgrp_tags'   => 'boolean',
        'enabled'       => 'boolean',
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
            $path = \App\Services\PlaylistStore::path($p->id);
            foreach (['', '-wal', '-shm'] as $suffix) {
                if (is_file($path . $suffix)) {
                    @unlink($path . $suffix);
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

    public function providerIds(): array
    {
        return $this->providers()->pluck('providers.id')->all();
    }

    public function toGridArray(): array
    {
        $store  = \App\Services\PlaylistStore::existsFor($this->id) ? new \App\Services\PlaylistStore($this->id) : null;
        $counts = $store ? $store->counts() : ['channels' => 0, 'groups' => 0];

        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'iplock'        => $this->iplock,
            'cipher'        => $this->cipher,
            'channel_start' => $this->channel_start,
            'enabled'       => (bool) $this->enabled,
            'channels'      => $counts['channels'],
            'groups'        => $counts['groups'],
            'last_touch_at' => optional($this->last_touch_at)->format('Y-m-d H:i:s'),
        ];
    }
}
