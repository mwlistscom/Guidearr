<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedLog extends Model
{
    protected $fillable = ['msgid', 'provider_id', 'user_id', 'level', 'message'];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public static function write(string $msgid, ?int $providerId, ?int $userId, string $level, string $message): self
    {
        return static::create([
            'msgid'       => $msgid,
            'provider_id' => $providerId,
            'user_id'     => $userId,
            'level'       => $level,
            'message'     => $message,
        ]);
    }
}
