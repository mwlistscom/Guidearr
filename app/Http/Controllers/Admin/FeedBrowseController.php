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

        return view('admin.feeds.users', compact('users'));
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

        if (! ProviderStore::exists($provider->id)) {
            return response()->json(['last_page' => 1, 'data' => []]);
        }
        $store = new ProviderStore($provider->id);
        $total = $store->channelCount($search);

        return response()->json([
            'last_page' => max(1, (int) ceil($total / $size)),
            'total'     => $total,
            'data'      => $store->channels($size, ($page - 1) * $size, $search),
        ]);
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
