<?php

namespace App\Models;

use App\Notifications\VerifyEmailCode;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    public function providers(): HasMany
    {
        return $this->hasMany(Provider::class);
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    protected static function booted(): void
    {
        // When an account is deleted (self-service Settings or admin), the DB cascade
        // removes providers, playlists + feed_queue but leaves the per-provider AND
        // per-playlist SQLite store FILES on disk (FK cascades don't touch the filesystem,
        // and a cascade fires no model events). Snapshot those file paths into the purge
        // queue so `php artisan feed:purge` can delete them afterwards.
        // feed_logs.user_id is nulled on delete, so clear the user's logs here first.
        static::deleting(function (User $u) {
            $payload = $u->providers()->get(['id', 'name'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'path' => ProviderStore::path($p->id),
                ])
                ->concat($u->playlists()->get(['id', 'name'])->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'kind' => 'playlist',
                    'path' => PlaylistStore::path($p->id),
                ]))
                ->values()->all();

            PurgeJob::create([
                'user_id' => $u->id,
                'email' => $u->email,
                'payload' => $payload,
                'state' => 'queued',
            ]);

            FeedLog::where('user_id', $u->id)->delete();
        });
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Mirror the column default (`status` is NOT NULL DEFAULT 'active'). Without it a freshly
     * created model holds status = null in memory until it is reloaded, and canSignIn() — now
     * checked on every request — would treat a brand-new active account as inactive.
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'verification_code_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    /**
     * May this account be signed in right now? The one rule every sign-in path and every
     * already-signed-in request checks: active, and not on the ban list (which is keyed by
     * email, so it can hold an address whose account status was never changed).
     */
    public function canSignIn(): bool
    {
        return $this->status === 'active' && ! $this->isBanned();
    }

    public function isBanned(): bool
    {
        return Ban::isBanned($this->email);
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /** Minutes a freshly issued verification code stays valid. */
    public const VERIFICATION_TTL_MINUTES = 15;

    /** Minutes a user must wait between requesting new verification codes. */
    public const VERIFICATION_RESEND_MINUTES = 5;

    /**
     * Wrong guesses one code survives. The route throttle (10/minute) alone allowed ~150 guesses
     * over a code's 15-minute life; after this many the code is void and a new one must be
     * requested — which the resend cooldown paces, so the guess rate stays bounded.
     */
    public const VERIFICATION_MAX_ATTEMPTS = 5;

    /**
     * Send a 6-digit verification code instead of the default verification link.
     */
    public function sendEmailVerificationNotification(): void
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'verification_code' => $code,
            'verification_code_expires_at' => now()->addMinutes(self::VERIFICATION_TTL_MINUTES),
        ])->save();

        RateLimiter::clear($this->verificationAttemptsKey());

        $this->notify(new VerifyEmailCode($code));
    }

    /**
     * When the current code was issued (derived from its expiry), or null if none.
     */
    public function verificationCodeSentAt(): ?CarbonInterface
    {
        return $this->verification_code_expires_at?->subMinutes(self::VERIFICATION_TTL_MINUTES);
    }

    /**
     * The earliest moment a new code may be requested, or null if one may be sent now.
     */
    public function verificationResendAvailableAt(): ?CarbonInterface
    {
        $sentAt = $this->verificationCodeSentAt();

        if ($sentAt === null) {
            return null;
        }

        $availableAt = $sentAt->addMinutes(self::VERIFICATION_RESEND_MINUTES);

        return $availableAt->isFuture() ? $availableAt : null;
    }

    /**
     * Whether the resend cooldown has elapsed (or no code has been sent yet).
     */
    public function canResendVerification(): bool
    {
        return $this->verificationResendAvailableAt() === null;
    }

    /**
     * Check a submitted code against the stored, unexpired code.
     */
    public function verifyCode(string $code): bool
    {
        if (! $this->verification_code || ! $this->verification_code_expires_at) {
            return false;
        }

        if ($this->verification_code_expires_at->isPast()) {
            return false;
        }

        if (hash_equals($this->verification_code, trim($code))) {
            RateLimiter::clear($this->verificationAttemptsKey());

            return true;
        }

        RateLimiter::hit($this->verificationAttemptsKey(), self::VERIFICATION_TTL_MINUTES * 60);

        if (RateLimiter::attempts($this->verificationAttemptsKey()) >= self::VERIFICATION_MAX_ATTEMPTS) {
            // Void the code but keep its expiry, so the resend cooldown still applies.
            $this->forceFill(['verification_code' => null])->save();
        }

        return false;
    }

    /** True when the current code was voided by too many wrong guesses (and has not expired). */
    public function verificationCodeLocked(): bool
    {
        return $this->verification_code === null
            && $this->verification_code_expires_at !== null
            && $this->verification_code_expires_at->isFuture();
    }

    private function verificationAttemptsKey(): string
    {
        return 'verify-code|'.$this->getKey();
    }
}
