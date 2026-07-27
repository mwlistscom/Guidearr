<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\User;
use App\Services\ProviderStore;
use Illuminate\Http\Request;

class FeedBrowseController extends Controller
{
    public function users()
    {
        $usersData = User::withCount(['providers', 'playlists'])
            ->withMax('providers as providers_touch_at', 'last_touch_at')
            ->withMax('playlists as playlists_touch_at', 'last_touch_at')
            ->orderBy('name')->get()
            ->map(function ($u) {
                // "Last touch" = the most recent human activity across the user's providers/playlists
                // (a served m3u/xtream download stamps last_touch_at even with no dashboard login).
                $touch = collect([$u->providers_touch_at, $u->playlists_touch_at])->filter()->max();

                return [
                    'id'        => $u->id,
                    'name'      => $u->name,
                    'email'     => $u->email,
                    'providers' => $u->providers_count,
                    'playlists' => $u->playlists_count,
                    'lastlogin' => optional($u->last_login_at)->format('Y-m-d H:i'),
                    'lasttouch' => $touch ? \Illuminate\Support\Carbon::parse($touch)->format('Y-m-d H:i') : null,
                    'url'       => route('admin.feeds.user', $u),
                    'delUrl'    => route('admin.users.destroy', $u),
                    'is_self'   => $u->id === auth()->id(),
                    'is_admin'  => (bool) $u->is_admin,
                ];
            })->values();

        $queue = \App\Models\FeedQueue::with([
            'provider:id,name,enabled,last_status,refresh_hour,refresh_minute,last_refresh_at',
            'user:id,name,email',
        ])->orderByDesc('updated_at')->limit(100)->get();

        $queueData = $queue->map(fn ($j) => [
            'id'       => $j->id,
            'provider' => $j->provider->name ?? '#' . $j->provider_id,
            'user_id'  => $j->user_id,
            'email'    => $j->user->email ?? '—',
            'type'     => $j->type,
            'state'    => $j->state,
            'attempts' => $j->attempts,
            'error'    => $j->error,
            'next'     => $this->nextStart($j->provider),
            'updated'  => optional($j->updated_at)->format('Y-m-d H:i:s'),
            // A disabled provider isn't pending work; surface WHY (cold-reaped vs otherwise disabled)
            // so the row can be badged + dimmed instead of showing a stale job state.
            'disabled' => $j->provider ? ! $j->provider->enabled : false,
            'cold'     => $j->provider && $j->provider->last_status === \App\Models\Provider::REAPED_STATUS,
        ])->values();

        $purges = \App\Models\PurgeJob::orderByDesc('updated_at')->limit(50)->get();

        return view('admin.feeds.users', compact('usersData', 'queueData', 'purges'));
    }

    /**
     * Human next-refresh time for a provider, mirroring FeedDue's daily-slot logic
     * (refresh_hour:refresh_minute). Disabled providers never refresh; a provider whose slot has
     * passed but hasn't refreshed since is "due now".
     */
    private function nextStart(?Provider $p): string
    {
        if (! $p) {
            return '—';
        }
        // Only enabled providers are ever enqueued (see FeedDue), so a disabled provider has no next
        // start — surface that plainly instead of a blank so it's obvious WHY there's no date.
        if (! $p->enabled) {
            return 'disabled';
        }

        $now  = now();
        $slot = $now->copy()->setTime((int) ($p->refresh_hour ?? 0), (int) ($p->refresh_minute ?? 0), 0);

        if ($now->lt($slot)) {
            return $slot->format('Y-m-d H:i');
        }
        if ($p->last_refresh_at !== null && $p->last_refresh_at->gte($slot)) {
            return $slot->copy()->addDay()->format('Y-m-d H:i');
        }

        return 'due now';
    }

    /** Inline-edit a feed_queue row (type/state pulldowns, attempts/error counters). */
    public function queueUpdate(Request $request, \App\Models\FeedQueue $job)
    {
        $field = (string) $request->input('field');
        $value = $request->input('value');

        $rules = [
            'type'     => ['in:' . implode(',', Provider::TYPES)],
            'state'    => ['in:' . implode(',', \App\Models\FeedQueue::STATES)],
            'attempts' => ['integer', 'min:0'],
            'error'    => ['integer', 'min:0'],
        ];
        if (! isset($rules[$field])) {
            return response()->json(['message' => 'That field is not editable.'], 422);
        }

        $v = \Illuminate\Support\Facades\Validator::make(['v' => $value], ['v' => $rules[$field]]);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }

        $job->update([$field => $value]);

        return response()->json(['ok' => true]);
    }

    /**
     * Manually trigger a refresh for a queued row's provider. A disabled/cold provider is re-enabled
     * and touched (so the reaper won't immediately disable it again), mirroring "access revives a
     * cold provider", then the job is enqueued for the worker to pick up.
     */
    public function queueRun(\App\Models\FeedQueue $job)
    {
        $provider = Provider::find($job->provider_id);
        if (! $provider) {
            return response()->json(['message' => 'Provider no longer exists.'], 404);
        }

        $provider->forceFill(['enabled' => true, 'last_touch_at' => now()])->save();
        \App\Models\FeedQueue::enqueue($provider);

        return response()->json(['ok' => true, 'message' => 'Refresh queued.']);
    }

    /**
     * Recent feed-log lines for a queue row's provider (across runs — a cold row's own run may have
     * been trimmed). Newest-run lines last, capped. Shape mirrors ProviderController::feedPayload.
     */
    public function queueLog(\App\Models\FeedQueue $job)
    {
        $lines = \App\Models\FeedLog::where('provider_id', $job->provider_id)
            ->orderByDesc('id')->limit(500)->get()
            ->sortBy('id')->values()
            ->map(fn (\App\Models\FeedLog $l) => [
                'level'   => $l->level,
                'message' => $l->message,
                'at'      => optional($l->created_at)->format('Y-m-d H:i:s'),
            ]);

        return response()->json([
            'provider' => $job->provider->name ?? '#'.$job->provider_id,
            'state'    => $job->state,
            'logs'     => $lines,
        ]);
    }

    /** Delete a feed_queue row; disable its provider so it is not re-queued. */
    public function queueDelete(\App\Models\FeedQueue $job)
    {
        Provider::whereKey($job->provider_id)->update(['enabled' => false]);
        $job->delete();

        return response()->json(['ok' => true, 'message' => 'Job removed; provider disabled.']);
    }

    public function providers(User $user)
    {
        $rows = $user->providers()->orderBy('name')->get()->map(fn (Provider $p) => [
            'provider' => $p,
            'channels' => ProviderStore::channelCountFor($p->id),
        ]);

        $playlists = \App\Models\Playlist::where('user_id', $user->id)->orderBy('name')->get()->map(function ($pl) {
            $counts = \App\Services\PlaylistStore::existsFor($pl->id)
                ? (new \App\Services\PlaylistStore($pl->id))->counts()
                : ['channels' => 0, 'groups' => 0];

            return ['playlist' => $pl, 'channels' => $counts['channels'], 'groups' => $counts['groups']];
        });

        return view('admin.feeds.providers', compact('user', 'rows', 'playlists'));
    }

    /** Admin read-only browse of a single playlist's channels. */
    public function playlist(\App\Models\Playlist $playlist)
    {
        $playlist->loadMissing('user');

        return view('admin.feeds.playlist', compact('playlist'));
    }

    public function playlistData(Request $request, \App\Models\Playlist $playlist)
    {
        $size   = min(200, max(10, (int) $request->query('size', 50)));
        $page   = max(1, (int) $request->query('page', 1));
        $search = $request->query('search');
        $group  = $request->query('group');

        if (! \App\Services\PlaylistStore::existsFor($playlist->id)) {
            return response()->json(['last_page' => 1, 'total' => 0, 'data' => []]);
        }

        $store = new \App\Services\PlaylistStore($playlist->id);
        $res   = $store->effectiveChannelPage($search, $group, 'all', $page, $size);

        $out = array_map(fn (array $r) => [
            'row'         => $r['row'],
            'name'        => $r['name'],
            'group_title' => $r['group_title'],
            'tvg_id'      => $r['tvg_id'],
            'url'         => $r['url'],
            'enabled'     => $r['enabled'],
            'deleted'     => $r['deleted'],
        ], $res['rows']);

        return response()->json(['last_page' => max(1, (int) ceil($res['total'] / $size)), 'total' => $res['total'], 'data' => $out]);
    }

    public function playlistGroups(\App\Models\Playlist $playlist)
    {
        $groups = \App\Services\PlaylistStore::existsFor($playlist->id)
            ? (new \App\Services\PlaylistStore($playlist->id))->groups()
            : [];

        return response()->json(['groups' => $groups]);
    }

    public function channels(Provider $provider)
    {
        $provider->loadMissing('user');

        return view('admin.feeds.channels', compact('provider'));
    }

    public function channelsData(Request $request, Provider $provider)
    {
        $size   = min(200, max(10, (int) $request->query('size', 50)));
        $page   = max(1, (int) $request->query('page', 1));
        $search = $request->query('search');
        $group  = $request->query('group');

        try {
            if (! ProviderStore::exists($provider->id)) {
                return response()->json(['last_page' => 1, 'total' => 0, 'data' => []]);
            }
            $store = new ProviderStore($provider->id);
            $total = $store->channelCount($search, $group);

            return response()->json([
                'last_page' => max(1, (int) ceil($total / $size)),
                'total'     => $total,
                'data'      => $store->channels($size, ($page - 1) * $size, $search, $group),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['last_page' => 1, 'total' => 0, 'data' => [], 'error' => $e->getMessage()]);
        }
    }

    public function groupsData(Provider $provider)
    {
        try {
            $groups = ProviderStore::exists($provider->id) ? (new ProviderStore($provider->id))->groups() : [];
            return response()->json(['groups' => $groups]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['groups' => [], 'error' => $e->getMessage()]);
        }
    }

    public function updateChannel(Request $request, Provider $provider, int $channel)
    {
        if (! ProviderStore::exists($provider->id)) {
            return response()->json(['message' => 'No channel store for this provider.'], 404);
        }
        $field = (string) $request->input('field');
        $ok    = (new ProviderStore($provider->id))->updateChannel($channel, $field, (string) $request->input('value'));

        return $ok
            ? response()->json(['ok' => true])
            : response()->json(['message' => "Field '{$field}' is not editable."], 422);
    }

    public function deleteChannel(Provider $provider, int $channel)
    {
        if (ProviderStore::exists($provider->id)) {
            (new ProviderStore($provider->id))->deleteChannel($channel);
        }

        return response()->json(['ok' => true]);
    }
}
