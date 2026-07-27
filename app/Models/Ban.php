<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * An email-keyed ban. Unlike users.status (which vanishes with the account), a Ban row persists
 * independently, so a banned person cannot re-register or sign in with the same address. Email is
 * always stored lower-cased/trimmed and compared the same way.
 */
class Ban extends Model
{
    protected $fillable = ['email', 'reason', 'banned_by'];

    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by');
    }

    /** Normalise an email to the canonical form used for storage and lookup. */
    public static function normalize(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }

    /** Is this email currently banned? Safe to call before the table exists (returns false). */
    public static function isBanned(?string $email): bool
    {
        $email = self::normalize($email);
        if ($email === '') {
            return false;
        }

        // Guard the pre-migration window: the enforcement callers (login pipeline, registration,
        // OAuth) run on every install, but the bans table may not exist yet on a not-yet-migrated
        // deploy. Never let a missing table break authentication.
        if (! Schema::hasTable('bans')) {
            return false;
        }

        return static::where('email', $email)->exists();
    }

    /**
     * Add (or update) a ban for an email. Idempotent — re-banning refreshes the reason/actor.
     * Returns the Ban row.
     */
    public static function ban(?string $email, ?string $reason = null, ?int $bannedBy = null): ?Ban
    {
        $email = self::normalize($email);
        if ($email === '') {
            return null;
        }

        return static::updateOrCreate(
            ['email' => $email],
            ['reason' => $reason, 'banned_by' => $bannedBy],
        );
    }

    /** Remove any ban for an email. Returns the number of rows deleted (0 or 1). */
    public static function unban(?string $email): int
    {
        $email = self::normalize($email);
        if ($email === '') {
            return 0;
        }

        return static::where('email', $email)->delete();
    }
}
