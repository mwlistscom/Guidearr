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
}
