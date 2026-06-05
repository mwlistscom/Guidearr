<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use App\Models\Provider;
use App\Services\PlaylistStore;
use App\Services\ProviderStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaintenanceController extends Controller
{
    /** Show stale playlists (by last-served activity) and a read-only provider activity table. */
    public function index(Request $request)
    {
        $days = max(0, min(3650, (int) $request->query('days', 30)));
        $cutoff = now()->subDays($days);

        $stale = Playlist::with('user')
            ->where(fn ($q) => $q->whereNull('last_touch_at')->orWhere('last_touch_at', '<', $cutoff))
            ->orderByRaw('last_touch_at is null desc')
            ->orderBy('last_touch_at', 'asc')
            ->get()
            ->map(fn (Playlist $p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'user'  => $p->user?->email ?? '—',
                'last'  => $p->last_touch_at,
                'bytes' => $this->fileBytes(PlaylistStore::path($p->id)),
            ]);

        // Provider activity (read-only) — linked-playlist counts in one grouped query.
        $linkCounts = DB::table('playlist_providers')
            ->select('provider_id', DB::raw('count(*) as c'))
            ->groupBy('provider_id')->pluck('c', 'provider_id');

        $providers = Provider::orderByRaw('last_touch_at is null desc')
            ->orderBy('last_touch_at', 'asc')->get()
            ->map(fn (Provider $p) => [
                'id'        => $p->id,
                'name'      => $p->name,
                'type'      => $p->type,
                'last'      => $p->last_touch_at,
                'playlists' => (int) ($linkCounts[$p->id] ?? 0),
                'bytes'     => $this->fileBytes(ProviderStore::path($p->id)),
            ]);

        return view('admin.maintenance', [
            'days'         => $days,
            'stale'        => $stale,
            'providers'    => $providers,
            'totalStale'   => $stale->count(),
            'reclaimBytes' => $stale->sum('bytes'),
        ]);
    }

    /** Delete the selected playlists (per-model delete fires the hook that unlinks the SQLite store). */
    public function prune(Request $request)
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;
        foreach (Playlist::whereIn('id', $data['ids'])->get() as $playlist) {
            $playlist->delete();
            $deleted++;
        }

        return redirect()->route('admin.maintenance')
            ->with('status', $deleted . ' playlist' . ($deleted === 1 ? '' : 's') . ' deleted.');
    }

    private function fileBytes(string $path): int
    {
        return is_file($path) ? ((int) @filesize($path)) : 0;
    }
}
