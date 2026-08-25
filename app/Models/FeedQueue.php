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
        'dstart', 'dstop', 'elapsed', 'hour', 'error', 'attempts', 'retry_after',
    ];

    protected function casts(): array
    {
        return [
            'dstart'   => 'datetime',
            'retry_after' => 'datetime',
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
                // An explicit enqueue — the scheduler's turn, or an operator pressing Run — is a
                // fresh start, so it clears any backoff a previous failure left behind.
                'retry_after' => null,
            ]
        );

        FeedLog::write($job->msgid, $provider->id, $provider->user_id, 'info', 'Queued for processing.');

        return $job;
    }

    /**
     * Open a status row for an in-request Xtream credential migration. Unlike enqueue(),
     * this is NOT picked up by a worker: state is 'running' with a null dstart, so
     * claimNext() (which claims 'queued') ignores it and reclaimOrphans() (which needs a
     * non-null dstart) leaves it alone. The migrator marks it done/error itself. Reusing a
     * FeedQueue row lets the existing "update log" overlay tail progress by msgid.
     */
    public static function openCredentialMigration(Provider $provider): self
    {
        return static::updateOrCreate(
            ['provider_id' => $provider->id],
            [
                'msgid'     => static::newMsgid(),
                'user_id'   => $provider->user_id,
                'type'      => $provider->type,
                'state'     => 'running',
                'processor' => 'credmigrate',
                'dstart'    => null,
                'dstop'     => null,
                'elapsed'   => 0,
                'hour'      => $provider->refresh_hour ?? 0,
                'error'     => 0,
                'attempts'  => 0,
                'retry_after' => null,
            ]
        );
    }

    /** Reset jobs stuck in 'running' past the orphan window (crashed/hung worker); count an error. */
    public static function reclaimOrphans(): int
    {
        $minutes = (int) config('guidearr.feed.orphan_minutes', 60);
        $orphans = static::where('state', 'running')
            ->whereNotNull('dstart')
            ->where('dstart', '<', now()->subMinutes($minutes))
            ->get();

        foreach ($orphans as $o) {
            // Back off here too. A job that hung once will likely hang again, and re-claiming it
            // the instant it is reset is what turns one wedged provider into a retry storm.
            $wait = static::backoffSeconds($o->error + 1);
            $o->forceFill([
                'state'       => 'queued',
                'error'       => $o->error + 1,
                'processor'   => null,
                'retry_after' => $wait > 0 ? now()->addSeconds($wait) : null,
            ])->save();
            $o->log('warn', "Job ran past {$minutes}m and was reset to queued; error #{$o->error}."
                .($wait > 0 ? " Next attempt in {$wait}s." : ''));
        }

        return $orphans->count();
    }

    /**
     * Seconds to wait before attempt N+1, given the error count that has just been recorded.
     *
     * Indexed from the first failure; past the end of the ladder the last step repeats. Returning
     * 0 means "claimable immediately", which is what an empty config restores.
     */
    public static function backoffSeconds(int $errorCount): int
    {
        $ladder = config('guidearr.feed.retry_backoff', []);
        if (! is_array($ladder) || $ladder === []) {
            return 0;
        }
        $ladder = array_values(array_map('intval', $ladder));
        $step = $ladder[min(max($errorCount, 1), count($ladder)) - 1];

        return max(0, $step);
    }

    /**
     * Queued AND past any retry backoff — the jobs a worker may actually take right now.
     *
     * The supervisor sizes its worker pool off the same scope. Counting a backed-off job as
     * backlog would have it spawn children that find nothing to claim and exit, every tick.
     */
    public function scopeClaimable($query)
    {
        return $query->where('state', 'queued')
            ->where(function ($w) {
                $w->whereNull('retry_after')->orWhere('retry_after', '<=', now());
            });
    }

    /** Atomically claim the next queued job. Uses SKIP LOCKED on MySQL for true 2–3 worker parallelism. */
    public static function claimNext(string $processor): ?self
    {
        static::reclaimOrphans();

        return DB::transaction(function () use ($processor) {
            $q = static::claimable()->orderBy('id');
            if (in_array($q->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $q->lock('FOR UPDATE SKIP LOCKED');
            } else {
                $q->lockForUpdate();
            }
            $job = $q->first();
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
            'elapsed' => $this->elapsedSeconds(),
        ])->save();
    }

    public function markError(int $code = 1): void
    {
        $this->forceFill([
            'state'   => 'error',
            'error'   => $code,
            'dstop'   => now(),
            'elapsed' => $this->elapsedSeconds(),
        ])->save();
    }

    /** Whole, non-negative seconds since dstart (Carbon 3 diff is a signed float). */
    private function elapsedSeconds(): int
    {
        return $this->dstart ? (int) abs(now()->diffInSeconds($this->dstart)) : 0;
    }

    public function log(string $level, string $message): FeedLog
    {
        return FeedLog::write($this->msgid, $this->provider_id, $this->user_id, $level, $message);
    }
}
