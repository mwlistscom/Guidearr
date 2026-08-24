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

    /** Provider queue states that mean "still importing — not safe to seed from yet". */
    private const BUSY_STATES = ['queued', 'running'];

    /** Providers (and guide options) for the create/setup modal. */
    public function options()
    {
        $providers = Provider::where('user_id', Auth::id())
            ->with('feedQueue')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                // A provider is only safe to seed a playlist from once its import has
                // finished AND it actually holds channels. Creating a playlist while a
                // provider is still loading seeds it empty forever (it is not auto-rebuilt).
                'channels' => ProviderStore::channelCountFor($p->id),
                'busy' => in_array(optional($p->feedQueue)->state, self::BUSY_STATES, true),
            ]);

        return response()->json(['providers' => $providers]);
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:64',
            'iplock' => 'nullable|string|max:64',
            'channel_start' => 'nullable|integer|min:1|max:1000000',
            'extgrp_tags' => 'boolean',
            'guide_provider_id' => 'nullable|integer',
            'providers' => 'array',
            'providers.*' => 'integer',
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

        // Guard against the "blank playlist" race: a playlist seeded from a provider that is
        // still importing (or has no channels yet) captures 0 channels and is never rebuilt.
        // Manual playlists (no providers selected) are exempt. This mirrors the client-side
        // block so it holds even if the modal is bypassed.
        if ($providerIds) {
            $selected = Provider::where('user_id', Auth::id())
                ->whereIn('id', $providerIds)
                ->with('feedQueue')
                ->get();
            foreach ($selected as $p) {
                if (in_array(optional($p->feedQueue)->state, self::BUSY_STATES, true)) {
                    return response()->json([
                        'message' => "Provider “{$p->name}” is still updating. Wait for it to finish, then create the playlist.",
                    ], 422);
                }
                if (ProviderStore::channelCountFor($p->id) < 1) {
                    return response()->json([
                        'message' => "Provider “{$p->name}” has no channels yet. Let it finish importing before adding it to a playlist.",
                    ], 422);
                }
            }
        }

        $guideId = (int) $request->input('guide_provider_id', 0);
        if ($guideId && ! in_array($guideId, $ownProviderIds, true)) {
            $guideId = 0;
        }

        $playlist = Playlist::create([
            'user_id' => Auth::id(),
            'name' => $request->string('name'),
            'iplock' => $request->input('iplock') ?: null,
            'channel_start' => (int) $request->input('channel_start', 100),
            'extgrp_tags' => $request->boolean('extgrp_tags', true),
            'guide_provider_id' => $guideId ?: null,
            'enabled' => true,
            'last_touch_at' => now(),
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

    public function update(Request $request, Playlist $playlist)
    {
        $this->authorizeOwner($playlist);
        $v = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:64',
            'iplock' => 'nullable|string|max:64',
            'channel_start' => 'sometimes|integer|min:1|max:1000000',
            'extgrp_tags' => 'sometimes|boolean',
            'enabled' => 'sometimes|boolean',
            'guide_provider_id' => 'nullable|integer',
        ]);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }

        $own = Provider::where('user_id', Auth::id())->pluck('id')->all();
        if ($request->has('name')) {
            $playlist->name = (string) $request->input('name');
        }
        if ($request->has('iplock')) {
            $playlist->iplock = $request->input('iplock') ?: null;
        }
        if ($request->has('channel_start')) {
            $playlist->channel_start = (int) $request->input('channel_start');
        }
        if ($request->has('extgrp_tags')) {
            $playlist->extgrp_tags = $request->boolean('extgrp_tags');
        }
        if ($request->has('enabled')) {
            $playlist->enabled = $request->boolean('enabled');
        }
        if ($request->has('guide_provider_id')) {
            $g = (int) $request->input('guide_provider_id', 0);
            $playlist->guide_provider_id = ($g && in_array($g, $own, true)) ? $g : null;
        }
        // cipher is intentionally NOT editable here
        $playlist->save();

        return response()->json(['ok' => true] + $playlist->toGridArray());
    }

    public function rotateKey(Playlist $playlist)
    {
        $this->authorizeOwner($playlist);
        $playlist->cipher = Playlist::freshCipher();
        $playlist->save();

        return response()->json(['ok' => true, 'cipher' => $playlist->cipher]);
    }

    private function authorizeOwner(Playlist $playlist): void
    {
        abort_unless($playlist->user_id === Auth::id(), 403);
    }

    // ---------- editor ----------

    public function channels(Request $request, Playlist $playlist)
    {
        $this->authorizeOwner($playlist);
        $size = min(200, max(10, (int) $request->query('size', 50)));
        $page = max(1, (int) $request->query('page', 1));
        $search = $request->query('search');
        $group = $request->query('group');
        $mode = $request->query('deleted') === 'all' ? 'all' : 'hide';

        // Self-heal: if the store file has gone missing, rebuild it from the attached providers
        // before loading (a no-op when the file exists or there is no provider data to seed from).
        if (! PlaylistStore::existsFor($playlist->id)) {
            $playlist->ensureStoreSeeded();
        }
        if (! PlaylistStore::existsFor($playlist->id)) {
            return response()->json(['last_page' => 1, 'total' => 0, 'data' => []]);
        }
        $store = new PlaylistStore($playlist->id);
        $res = $store->effectiveChannelPage($search, $group, $mode, $page, $size);

        return response()->json([
            'last_page' => max(1, (int) ceil($res['total'] / $size)),
            'total' => $res['total'],
            'data' => $res['rows'],
        ]);
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
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }
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
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }
        $id = (new PlaylistStore($playlist->id))->addManualChannel($request->only(['name', 'url', 'group', 'tvg_logo', 'tvg_id']));

        return response()->json(['id' => $id]);
    }

    public function updateChannel(Request $request, Playlist $playlist, int $cid)
    {
        $this->authorizeOwner($playlist);
        $store = new PlaylistStore($playlist->id);
        if ($request->has('enabled')) {
            $store->setChannelFlag($cid, 'enabled', $request->boolean('enabled'));
        }
        $store->updateChannel($cid, $request->only(['group_title', 'name', 'url', 'tvg_id', 'tvg_logo', 'tvg_name']));

        return response()->json(['ok' => true]);
    }

    public function moveChannel(Request $request, Playlist $playlist, int $cid)
    {
        $this->authorizeOwner($playlist);
        $store = new PlaylistStore($playlist->id);

        // Drag-and-drop anchors on the neighbouring row it was dropped against, so it lands there
        // whatever the grid is filtered to, paged at, or sorted by.
        $after = (int) $request->input('after_id', 0);
        $before = (int) $request->input('before_id', 0);
        if ($after > 0 || $before > 0) {
            return response()->json(['ok' => $store->moveChannelRelative($cid, $after ?: null, $before ?: null)]);
        }

        // "Move to row #". While a filter is active the number means the position in the FILTERED
        // view — what the "#" column shows — so resolve it against that list rather than treating
        // it as a global row, which would fling the channel to a different part of the playlist.
        $row = max(1, (int) $request->input('row', 1));
        $search = trim((string) $request->input('search', ''));
        $group = trim((string) $request->input('group', ''));
        $mode = $request->input('deleted') === 'all' ? 'all' : 'hide';

        if ($search !== '' || $group !== '') {
            $ids = $store->filteredChannelIds($search ?: null, $group ?: null, $mode, $cid);
            if ($ids) {
                $ok = $row <= 1
                    ? $store->moveChannelRelative($cid, null, $ids[0])
                    : $store->moveChannelRelative($cid, $ids[min($row - 2, count($ids) - 1)], null);

                return response()->json(['ok' => $ok]);
            }
        }

        $store->moveChannelToRow($cid, $row);

        return response()->json(['ok' => true]);
    }

    public function moveChannelsBulk(Request $request, Playlist $playlist)
    {
        $this->authorizeOwner($playlist);
        $v = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'row' => 'required|integer|min:1',
        ]);
        if ($v->fails()) {
            return response()->json(['message' => $v->errors()->first()], 422);
        }

        if (! PlaylistStore::existsFor($playlist->id)) {
            return response()->json(['moved' => 0]);
        }
        $data = $v->validated();
        $moved = (new PlaylistStore($playlist->id))->moveChannelsBulk($data['ids'], (int) $data['row']);

        return response()->json(['moved' => $moved]);
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
        if ($request->has('enabled')) {
            $store->setGroupFlagCascade($gid, 'enabled', $request->boolean('enabled'));
        }
        if ($request->filled('group_title')) {
            $store->renameGroup($gid, (string) $request->input('group_title'));
        }

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

    public function guide(Request $request, Playlist $playlist)
    {
        $this->authorizeOwner($playlist);
        $tvg = trim((string) $request->query('tvg_id', ''));
        $gid = (int) $playlist->guide_provider_id;
        if ($gid <= 0) {
            return response()->json(['programmes' => [], 'reason' => 'no guide source set for this playlist']);
        }
        if ($tvg === '') {
            return response()->json(['programmes' => [], 'reason' => 'this channel has no tvg-id']);
        }
        if (! ProviderStore::exists($gid)) {
            return response()->json(['programmes' => [], 'reason' => 'guide provider has no data yet']);
        }
        $from = now()->timestamp - 6 * 3600;
        $rows = (new ProviderStore($gid))->guideProgrammesFor($tvg, $from, 300);

        return response()->json(['programmes' => $rows]);
    }

    public function reindex(Request $request, Playlist $playlist)
    {
        $this->authorizeOwner($playlist);
        if (! PlaylistStore::existsFor($playlist->id)) {
            return response()->json(['ok' => true, 'count' => 0]);
        }
        $store = new PlaylistStore($playlist->id);
        $scope = $request->input('scope', 'all');
        $count = 0;
        if ($scope === 'channels' || $scope === 'all') {
            $count += $store->reindexChannels();
        }
        if ($scope === 'groups' || $scope === 'all') {
            $count += $store->reindexGroups();
        }

        return response()->json(['ok' => true, 'count' => $count]);
    }
}
