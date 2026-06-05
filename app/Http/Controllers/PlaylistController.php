<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\Provider;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PlaylistController extends Controller
{
    public function index()
    {
        return view('playlists.index');
    }

    public function data()
    {
        $rows = Playlist::where('user_id', Auth::id())
            ->orderBy('name')
            ->get()
            ->map->toGridArray();

        return response()->json($rows);
    }

    /** Providers (and guide options) for the create/setup modal. */
    public function options()
    {
        $providers = Provider::where('user_id', Auth::id())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]);

        return response()->json(['providers' => $providers]);
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name'              => 'required|string|max:64',
            'iplock'            => 'nullable|string|max:64',
            'channel_start'     => 'nullable|integer|min:1|max:1000000',
            'extgrp_tags'       => 'boolean',
            'guide_provider_id' => 'nullable|integer',
            'providers'         => 'array',
            'providers.*'       => 'integer',
        ]);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }

        // keep only providers this user actually owns
        $ownProviderIds = Provider::where('user_id', Auth::id())->pluck('id')->all();
        $providerIds = array_values(array_intersect(
            array_map('intval', $request->input('providers', [])),
            $ownProviderIds
        ));

        $guideId = (int) $request->input('guide_provider_id', 0);
        if ($guideId && ! in_array($guideId, $ownProviderIds, true)) {
            $guideId = 0;
        }

        $playlist = Playlist::create([
            'user_id'           => Auth::id(),
            'name'              => $request->string('name'),
            'iplock'            => $request->input('iplock') ?: null,
            'channel_start'     => (int) $request->input('channel_start', 100),
            'extgrp_tags'       => $request->boolean('extgrp_tags', true),
            'guide_provider_id' => $guideId ?: null,
            'enabled'           => true,
            'last_touch_at'     => now(),
        ]);

        if ($providerIds) {
            $playlist->providers()->sync($providerIds);
            $store = new PlaylistStore($playlist->id);
            foreach ($providerIds as $pid) {
                if (ProviderStore::exists($pid)) {
                    $store->seedFromProvider($pid, new ProviderStore($pid));
                }
            }
        }

        return response()->json(['id' => $playlist->id] + $playlist->toGridArray());
    }

    public function destroy(Playlist $playlist)
    {
        $this->authorizeOwner($playlist);
        $playlist->delete(); // model hook unlinks the SQLite file; pivot cascades

        return response()->json(['ok' => true]);
    }

    private function authorizeOwner(Playlist $playlist): void
    {
        abort_unless($playlist->user_id === Auth::id(), 403);
    }

    // ---------- editor ----------

    public function channels(Request $request, Playlist $playlist)
    {
        $this->authorizeOwner($playlist);
        $size    = min(200, max(10, (int) $request->query('size', 50)));
        $page    = max(1, (int) $request->query('page', 1));
        $search  = $request->query('search');
        $group   = $request->query('group');
        $mode    = $request->query('deleted') === 'all' ? 'all' : 'hide';

        if (! PlaylistStore::existsFor($playlist->id)) {
            return response()->json(['last_page' => 1, 'total' => 0, 'data' => []]);
        }
        $store = new PlaylistStore($playlist->id);
        $total = $store->channelCount($search, $group, $mode);
        $rows  = $store->channels($size, ($page - 1) * $size, $search, $group, $mode);

        // hydrate provider-channel rows from their provider stores (manual rows already inline)
        $byProvider = [];
        foreach ($rows as $r) {
            if ((int) $r['provider_id'] > 0) { $byProvider[(int) $r['provider_id']][] = (int) $r['channel_id']; }
        }
        $data = [];
        foreach ($byProvider as $pid => $ids) {
            $data[$pid] = ProviderStore::exists($pid) ? (new ProviderStore($pid))->channelsByIds($ids) : [];
        }

        $globalBase = ($page - 1) * $size;
        $out = [];
        foreach ($rows as $i => $r) {
            $pid = (int) $r['provider_id'];
            $src = $pid > 0 ? ($data[$pid][(int) $r['channel_id']] ?? null) : null;
            // playlist-level edit (non-empty pointer field) overrides the provider's value
            $pick = function (string $k) use ($r, $src) {
                $v = $r[$k] ?? '';
                return ($v !== '' && $v !== null) ? $v : ($src[$k] ?? '');
            };
            $name = $pick('name');
            $out[] = [
                'id'          => (int) $r['id'],
                'row'         => $globalBase + $i + 1,
                'provider_id' => $pid,
                'channel_id'  => (int) $r['channel_id'],
                'manual'      => $pid === 0,
                'missing'     => $pid > 0 && $src === null,
                'name'        => $name !== '' ? $name : ($pid > 0 && $src === null ? '(missing channel)' : ''),
                'tvg_name'    => $pick('tvg_name'),
                'tvg_id'      => $pick('tvg_id'),
                'tvg_logo'    => $pick('tvg_logo'),
                'url'         => $pick('url'),
                'group_title' => $r['group_title'],
                'enabled'     => (bool) $r['enabled'],
                'deleted'     => (bool) $r['deleted'],
            ];
        }

        return response()->json(['last_page' => max(1, (int) ceil($total / $size)), 'total' => $total, 'data' => $out]);
    }

    public function groups(Request $request, Playlist $playlist)
    {
        $this->authorizeOwner($playlist);
        $store = PlaylistStore::existsFor($playlist->id) ? new PlaylistStore($playlist->id) : null;
        $includeDeleted = $request->query('deleted') === 'all';

        return response()->json(['groups' => $store ? $store->groups($includeDeleted) : []]);
    }

    public function addGroupRow(Request $request, Playlist $playlist)
    {
        $this->authorizeOwner($playlist);
        $v = Validator::make($request->all(), ['group_title' => 'required|string|max:128']);
        if ($v->fails()) { return response()->json(['message' => $v->errors()->first()], 422); }
        $id = (new PlaylistStore($playlist->id))->addGroup((string) $request->input('group_title'));

        return response()->json(['id' => $id]);
    }

    public function addChannel(Request $request, Playlist $playlist)
    {
        $this->authorizeOwner($playlist);
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:255', 'url' => 'required|string|max:2048', 'group' => 'nullable|string|max:128',
            'tvg_logo' => 'nullable|string|max:2048', 'tvg_id' => 'nullable|string|max:128',
        ]);
        if ($v->fails()) { return response()->json(['message' => $v->errors()->first()], 422); }
        $id = (new PlaylistStore($playlist->id))->addManualChannel($request->only(['name', 'url', 'group', 'tvg_logo', 'tvg_id']));

        return response()->json(['id' => $id]);
    }

    public function updateChannel(Request $request, Playlist $playlist, int $cid)
    {
        $this->authorizeOwner($playlist);
        $store = new PlaylistStore($playlist->id);
        if ($request->has('enabled')) { $store->setChannelFlag($cid, 'enabled', $request->boolean('enabled')); }
        $store->updateChannel($cid, $request->only(['group_title', 'name', 'url', 'tvg_id', 'tvg_logo', 'tvg_name']));

        return response()->json(['ok' => true]);
    }

    public function moveChannel(Request $request, Playlist $playlist, int $cid)
    {
        $this->authorizeOwner($playlist);
        (new PlaylistStore($playlist->id))->moveChannelToRow($cid, max(1, (int) $request->input('row', 1)));

        return response()->json(['ok' => true]);
    }

    public function deleteChannel(Request $request, Playlist $playlist, int $cid)
    {
        $this->authorizeOwner($playlist);
        (new PlaylistStore($playlist->id))->setChannelFlag($cid, 'deleted', ! $request->boolean('restore'));

        return response()->json(['ok' => true]);
    }

    public function updateGroup(Request $request, Playlist $playlist, int $gid)
    {
        $this->authorizeOwner($playlist);
        $store = new PlaylistStore($playlist->id);
        if ($request->has('enabled')) { $store->setGroupFlagCascade($gid, 'enabled', $request->boolean('enabled')); }
        if ($request->filled('group_title')) { $store->renameGroup($gid, (string) $request->input('group_title')); }

        return response()->json(['ok' => true]);
    }

    public function moveGroup(Request $request, Playlist $playlist, int $gid)
    {
        $this->authorizeOwner($playlist);
        (new PlaylistStore($playlist->id))->moveGroupToRow($gid, max(1, (int) $request->input('row', 1)));

        return response()->json(['ok' => true]);
    }

    public function deleteGroup(Request $request, Playlist $playlist, int $gid)
    {
        $this->authorizeOwner($playlist);
        (new PlaylistStore($playlist->id))->setGroupFlagCascade($gid, 'deleted', ! $request->boolean('restore'));

        return response()->json(['ok' => true]);
    }
}
