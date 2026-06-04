<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderRefreshLog extends Model
{
    protected $fillable = [
        'provider_id', 'started_at', 'finished_at', 'status', 'message', 'bytes',
    ];

    protected function casts(): array
    {
        return [
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
            'bytes'       => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
