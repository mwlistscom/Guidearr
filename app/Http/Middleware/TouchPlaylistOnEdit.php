<?php

namespace App\Http\Middleware;

use App\Models\Playlist;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mark a playlist (and its backing providers) touched after a successful *edit*. Self-scoping: it
 * no-ops unless the matched route has a bound {playlist} and the request is an unsafe method
 * (POST/PUT/PATCH/DELETE), so it can live on the whole auth group and only fires on real mutations —
 * playlist reads (data/channels/groups/guide) and non-playlist routes pass straight through.
 *
 * This is why editing a playlist in the dashboard keeps its providers warm even without a client
 * serve, feeding the same last_touch_at signal the cold-provider reaper uses.
 */
class TouchPlaylistOnEdit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe()) {
            $playlist = $request->route('playlist');
            // $playlist->exists guards the destroy path — a just-deleted model must not be resurrected.
            if ($playlist instanceof Playlist && $playlist->exists
                && $response->isSuccessful()) {
                $playlist->markTouched();
            }
        }

        return $response;
    }
}
