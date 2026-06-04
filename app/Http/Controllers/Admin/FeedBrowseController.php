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
        $users = User::withCount('providers')->orderBy('name')->get();

        $queue = \App\Models\FeedQueue::with(['provider:id,name', 'user:id,name,email'])
            ->orderByDesc('updated_at')->limit(100)->get();

        $queueData = $queue->map(fn ($j) => [
            'id'       => $j->id,
            'provider' => $j->provider->name ?? '#' . $j->provider_id,
            'email'    => $j->user->email ?? '—',
            'type'     => $j->type,
            'state'    => $j->state,
            'attempts' => $j->attempts,
            'error'    => $j->error,
            'updated'  => optional($j->updated_at)->format('Y-m-d H:i:s'),
        ])->values();

        $purges = \App\Models\PurgeJob::orderByDesc('updated_at')->limit(50)->get();

        return view('admin.feeds.users', compact('users', 'queueData', 'purges'));
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

        return view('admin.feeds.providers', compact('user', 'rows'));
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
