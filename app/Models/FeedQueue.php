<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FeedQueue extends Model
{
    protected $table = 'feed_queue';

    public const STATES = ['queued', 'running', 'done', 'error'];

    protected $fillable = [
        'msgid', 'user_id', 'provider_id', 'type', 'state', 'processor',
        'dstart', 'dstop', 'elapsed', 'hour', 'error', 'attempts',
    ];

    protected function casts(): array
    {
        return [
            'dstart'   => 'datetime',
            'dstop'    => 'datetime',
            'elapsed'  => 'integer',
            'hour'     => 'integer',
            'error'    => 'integer',
            'attempts' => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function newMsgid(): string
    {
        do {
            $id = Str::random(12);
        } while (static::where('msgid', $id)->exists());

        return $id;
    }

    /**
     * Enqueue (or re-queue) a provider for background processing.
     * One row per provider (unique provider_id); re-queue resets it with a fresh msgid.
     */
    public static function enqueue(Provider $provider): self
    {
        $msgid = static::newMsgid();

        $job = static::updateOrCreate(
            ['provider_id' => $provider->id],
            [
                'msgid'     => $msgid,
                'user_id'   => $provider->user_id,
                'type'      => $provider->type,
                'state'     => 'queued',
                'processor' => null,
                'dstart'    => null,
                'dstop'     => null,
                'elapsed'   => 0,
                'hour'      => $provider->refresh_hour ?? 0,
                'error'     => 0,
                'attempts'  => 0,
            ]
        );

        FeedLog::write($job->msgid, $provider->id, $provider->user_id, 'info', 'Queued for processing.');

        return $job;
    }

    /** Atomically claim the next queued job (single-worker safe; add SKIP LOCKED for heavy concurrency). */
    public static function claimNext(string $processor): ?self
    {
        return DB::transaction(function () use ($processor) {
            $job = static::where('state', 'queued')->orderBy('id')->lockForUpdate()->first();
            if (! $job) {
                return null;
            }
            $job->forceFill([
                'state'     => 'running',
                'processor' => $processor,
                'dstart'    => now(),
                'attempts'  => $job->attempts + 1,
            ])->save();

            return $job;
        });
    }

    public function markDone(): void
    {
        $this->forceFill([
            'state'   => 'done',
            'dstop'   => now(),
            'elapsed' => $this->dstart ? now()->diffInSeconds($this->dstart) : 0,
        ])->save();
    }

    public function markError(int $code = 1): void
    {
        $this->forceFill([
            'state'   => 'error',
            'error'   => $code,
            'dstop'   => now(),
            'elapsed' => $this->dstart ? now()->diffInSeconds($this->dstart) : 0,
        ])->save();
    }

    public function log(string $level, string $message): FeedLog
    {
        return FeedLog::write($this->msgid, $this->provider_id, $this->user_id, $level, $message);
    }
}
