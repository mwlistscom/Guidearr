<?php

namespace App\Models;

use App\Notifications\VerifyEmailCode;
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

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    protected static function booted(): void
    {
        // When an account is deleted (self-service Settings or admin), the DB cascade
        // removes providers + feed_queue but leaves the per-provider SQLite store FILES
        // on disk (FK cascades don't touch the filesystem). Snapshot those file paths
        // into the purge queue so `php artisan feed:purge` can delete them afterwards.
        // feed_logs.user_id is nulled on delete, so clear the user's logs here first.
        static::deleting(function (User $u) {
            $payload = $u->providers()->get(['id', 'name'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'path' => ProviderStore::path($p->id),
                ])->all();

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
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
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
     * Send a 6-digit verification code instead of the default verification link.
     */
    public function sendEmailVerificationNotification(): void
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'verification_code' => $code,
            'verification_code_expires_at' => now()->addMinutes(self::VERIFICATION_TTL_MINUTES),
        ])->save();

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

        return hash_equals($this->verification_code, trim($code));
    }
}
