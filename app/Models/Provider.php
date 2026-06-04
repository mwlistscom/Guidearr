<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    public const TYPES = ['xtream', 'm3u', 'xmltv', 'manual'];

    protected $fillable = [
        'user_id', 'name', 'type', 'url', 'username', 'password',
        'timeshift', 'myshift', 'enabled', 'refresh_hour', 'refresh_minute',
        'last_refresh_at', 'last_status', 'last_touch_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled'         => 'boolean',
            'myshift'         => 'integer',
            'refresh_hour'    => 'integer',
            'refresh_minute'  => 'integer',
            'last_refresh_at' => 'datetime',
            'last_touch_at'   => 'datetime',
            'password'        => 'encrypted',
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
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refreshLogs(): HasMany
    {
        return $this->hasMany(ProviderRefreshLog::class)->latest('started_at');
    }

    /** Update the "last touched" marker for any user action. */
    public function markTouched(): void
    {
        $this->forceFill(['last_touch_at' => now()])->save();
    }

    public function requiresUrl(): bool
    {
        return $this->type !== 'manual';
    }

    /** Safe representation for the grid (never exposes the password). */
    public function toGridArray(): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'url'             => $this->url,
            'type'            => $this->type,
            'enabled'         => (bool) $this->enabled,
            'timeshift'       => $this->timeshift,
            'myshift'         => $this->myshift,
            'refresh_hour'    => $this->refresh_hour,
            'last_status'     => $this->last_status,
            'last_refresh_at' => optional($this->last_refresh_at)->format('Y-m-d H:i:s'),
            'last_touch_at'   => optional($this->last_touch_at)->format('Y-m-d H:i:s'),
        ];
    }
}
