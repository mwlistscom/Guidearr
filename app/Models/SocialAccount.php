<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a local user to an external OAuth identity (Google / Facebook). One row per
 * (provider, provider_id); a user may have several (e.g. both Google and Facebook).
 */
class SocialAccount extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_id', 'avatar'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
