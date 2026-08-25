<?php

namespace Tests\Feature;

use App\Console\Commands\FeedSupervise;
use App\Models\FeedQueue;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A failed refresh must WAIT before its next attempt.
 *
 * Requeueing with no delay meant the drain loop re-claimed the job within milliseconds, so one
 * dead upstream produced max_errors failures in about a second and auto-disabled the provider.
 * Seen in production on 2026-08-23: provider 15 went from error #1 to "disabled after 4 errors"
 * in a single second, three times over. The budget is meant to span separate attempts.
 */
class FeedRetryBackoffTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): Provider
    {
        $u = User::factory()->create(['email_verified_at' => now()]);

        return Provider::create([
            'user_id' => $u->id, 'name' => 'P', 'type' => 'm3u',
            'url' => 'http://h/x.m3u', 'enabled' => true, 'refresh_hour' => 2,
        ]);
    }

    /** Mirrors FeedWork::failJob()'s requeue branch. */
    private function failOnce(FeedQueue $job): void
    {
        $job->forceFill(['error' => $job->error + 1])->save();
        $wait = FeedQueue::backoffSeconds($job->error);
        $job->forceFill([
            'state' => 'queued',
            'processor' => null,
            'retry_after' => $wait > 0 ? now()->addSeconds($wait) : null,
        ])->save();
    }

    // ---- the ladder ----

    public function test_backoff_grows_and_the_last_step_repeats(): void
    {
        config(['guidearr.feed.retry_backoff' => [60, 300, 900]]);

        $this->assertSame(60, FeedQueue::backoffSeconds(1));
        $this->assertSame(300, FeedQueue::backoffSeconds(2));
        $this->assertSame(900, FeedQueue::backoffSeconds(3));
        $this->assertSame(900, FeedQueue::backoffSeconds(4), 'past the ladder the last step repeats');
        $this->assertSame(900, FeedQueue::backoffSeconds(99));
    }

    public function test_an_empty_ladder_restores_immediate_retries(): void
    {
        config(['guidearr.feed.retry_backoff' => []]);
        $this->assertSame(0, FeedQueue::backoffSeconds(1));
        $this->assertSame(0, FeedQueue::backoffSeconds(3));
    }

    public function test_the_shipped_default_spans_a_useful_window(): void
    {
        $ladder = config('guidearr.feed.retry_backoff');
        $this->assertNotEmpty($ladder, 'a default ladder must ship, or the bug returns');
        $this->assertSame($ladder, array_values(array_filter($ladder, fn ($n) => $n > 0)));

        $total = 0;
        for ($e = 1; $e < (int) config('guidearr.feed.max_errors', 4); $e++) {
            $total += FeedQueue::backoffSeconds($e);
        }
        $this->assertGreaterThanOrEqual(600, $total, 'the budget must span at least ten minutes');
    }

    // ---- claiming ----

    public function test_a_backed_off_job_cannot_be_claimed_until_its_time(): void
    {
        config(['guidearr.feed.retry_backoff' => [60]]);
        $job = FeedQueue::enqueue($this->provider());

        $this->assertNotNull(FeedQueue::claimNext('w1'), 'claimable before any failure');
        $this->failOnce($job->refresh());

        $this->assertNull(FeedQueue::claimNext('w1'), 'must not be re-claimed straight away');

        Carbon::setTestNow(now()->addSeconds(61));
        $this->assertNotNull(FeedQueue::claimNext('w1'), 'claimable once the wait has passed');
        Carbon::setTestNow();
    }

    /** The production failure: the whole budget must not burn in one second. */
    public function test_the_error_budget_is_not_spent_in_one_second(): void
    {
        config(['guidearr.feed.retry_backoff' => [60, 300, 900], 'guidearr.feed.max_errors' => 4]);
        $job = FeedQueue::enqueue($this->provider());

        $start = now();
        Carbon::setTestNow($start);

        $attempts = 0;
        while ($attempts < 10) {
            $claimed = FeedQueue::claimNext('w1');
            if (! $claimed) {
                break;
            }
            $attempts++;
            $this->failOnce($claimed);
        }

        // Everything above happened at one instant — exactly the drain loop's timing.
        $this->assertSame(1, $attempts, 'only one attempt may happen without time passing');
        $this->assertLessThan(4, FeedQueue::first()->error, 'the provider must not be disabled instantly');
        $this->assertTrue(now()->equalTo($start));
        Carbon::setTestNow();
    }

    public function test_each_successive_failure_waits_longer(): void
    {
        config(['guidearr.feed.retry_backoff' => [60, 300, 900]]);
        $job = FeedQueue::enqueue($this->provider());
        Carbon::setTestNow(now());

        $waits = [];
        foreach ([60, 300, 900] as $expected) {
            $claimed = FeedQueue::claimNext('w1');
            $this->assertNotNull($claimed, 'should be claimable at this point');
            $this->failOnce($claimed);
            $fresh = FeedQueue::first();
            $waits[] = (int) round($fresh->retry_after->getTimestamp() - now()->getTimestamp());
            Carbon::setTestNow(now()->addSeconds($expected + 1));
        }

        $this->assertSame([60, 300, 900], $waits);
        Carbon::setTestNow();
    }

    // ---- bypasses ----

    /** Pressing "Run" in the admin, or the scheduler's turn, must not sit behind a backoff. */
    public function test_enqueue_clears_the_backoff(): void
    {
        config(['guidearr.feed.retry_backoff' => [900]]);
        $p = $this->provider();
        $job = FeedQueue::enqueue($p);
        $this->failOnce($job->refresh());
        $this->assertNull(FeedQueue::claimNext('w1'));

        FeedQueue::enqueue($p);

        $this->assertNull(FeedQueue::first()->retry_after);
        $this->assertSame(0, FeedQueue::first()->error, 'a fresh enqueue also resets the count');
        $this->assertNotNull(FeedQueue::claimNext('w1'));
    }

    // ---- the supervisor ----

    /** Backlog must exclude backed-off jobs, or the pool churns workers that can claim nothing. */
    public function test_backed_off_jobs_are_not_counted_as_backlog(): void
    {
        config(['guidearr.feed.retry_backoff' => [600]]);
        $job = FeedQueue::enqueue($this->provider());

        $this->assertSame(1, FeedSupervise::backlogCount());
        $this->assertSame(1, FeedSupervise::slotsToSpawn(2, 0, FeedSupervise::backlogCount()));

        $this->failOnce($job->refresh());

        $this->assertSame(1, FeedQueue::where('state', 'queued')->count(), 'still queued...');
        $this->assertSame(0, FeedSupervise::backlogCount(), '...but the supervisor must not see it');
        $this->assertSame(0, FeedSupervise::slotsToSpawn(2, 0, FeedSupervise::backlogCount()),
            'or it spawns workers that can claim nothing');
    }

    // ---- the real worker, not a mirror of it ----

    /**
     * Drive feed:work itself against an unreachable provider. The mirror above would keep passing
     * if failJob() stopped setting the backoff, so this pins the actual code path: one worker pass
     * must cost exactly ONE error and leave the provider enabled and waiting.
     */
    public function test_a_worker_pass_against_a_dead_upstream_costs_one_error_not_four(): void
    {
        config(['guidearr.feed.retry_backoff' => [60, 300, 900], 'guidearr.feed.max_errors' => 4]);
        $p = $this->provider();
        FeedQueue::enqueue($p);

        // --drain would take every claimable job; with the backoff there is only ever one.
        $this->artisan('feed:work', ['--drain' => true])->assertSuccessful();

        $job = FeedQueue::first();
        $this->assertNotNull($job, 'the job must survive — 4 errors would have deleted it');
        $this->assertSame(1, $job->error, 'one pass, one error');
        $this->assertSame('queued', $job->state);
        $this->assertNotNull($job->retry_after, 'failJob() must set the backoff');
        $this->assertTrue($job->retry_after->greaterThan(now()));
        $this->assertTrue($p->fresh()->enabled, 'the provider must NOT be disabled by one failure');
        $this->assertNull(FeedQueue::claimNext('w1'), 'and it must not be immediately re-claimable');
    }

    /** With no backoff configured, the same pass reproduces the original bug — budget gone at once. */
    public function test_without_a_backoff_the_same_pass_disables_the_provider(): void
    {
        config(['guidearr.feed.retry_backoff' => [], 'guidearr.feed.max_errors' => 4]);
        $p = $this->provider();
        FeedQueue::enqueue($p);

        $this->artisan('feed:work', ['--drain' => true])->assertSuccessful();

        $this->assertFalse($p->fresh()->enabled, 'this is the behaviour the ladder exists to prevent');
        $this->assertNull(FeedQueue::first(), 'and the job is deleted at the threshold');
    }

    // ---- orphans ----

    public function test_a_reclaimed_orphan_also_waits(): void
    {
        config(['guidearr.feed.retry_backoff' => [60], 'guidearr.feed.orphan_minutes' => 60]);
        $job = FeedQueue::enqueue($this->provider());
        $job->forceFill(['state' => 'running', 'dstart' => now()->subMinutes(90), 'processor' => 'dead'])->save();

        $this->assertSame(1, FeedQueue::reclaimOrphans());

        $fresh = FeedQueue::first();
        $this->assertSame('queued', $fresh->state);
        $this->assertSame(1, $fresh->error);
        $this->assertNotNull($fresh->retry_after, 'a hung job must not be re-claimed instantly');
        $this->assertNull(FeedQueue::claimNext('w1'));
    }
}
