<?php

namespace App\Http\Middleware;

use App\Models\Playlist;
use App\Models\Provider;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamp last_touch_at from REAL USER ACTIVITY in the dashboard — viewing as well as editing.
 * This is the only signal the cold-provider reaper (providers:reap-cold) keys off, together with
 * a client serve (PlaylistServeController) and the explicit stamps in the provider/playlist
 * controllers. Nothing automated (worker refresh, migrators, schedulers) may write it.
 *
 * What counts, on any successful response:
 *   - a request to a route with a bound {playlist} → that playlist + its backing providers
 *   - a request to a route with a bound {provider} → that provider
 *   - opening the playlists list/grid            → all of the user's playlists (+ their providers)
 *   - opening the providers list/grid            → all of the user's providers
 *
 * The two list routes are what keep a provider with NO playlist attached warm: without them its
 * only activity would be an edit, so simply using the dashboard would not stop it going cold.
 *
 * Writes are throttled to once per THROTTLE_MINUTES per row, so a grid that reloads (or a tab
 * left polling) does not turn into a write storm — day-granularity is all the reaper needs.
 */
class TouchUserActivity
{
    /** Don't re-stamp a row that was already touched within this many minutes. */
    private const THROTTLE_MINUTES = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if (! $user || ! $response->isSuccessful()) {
            return $response;
        }

        $stale = now()->subMinutes(self::THROTTLE_MINUTES);

        // $model->exists guards the destroy paths — a just-deleted row must not be resurrected.
        $playlist = $request->route('playlist');
        if ($playlist instanceof Playlist && $playlist->exists) {
            if ($this->isStale($playlist->last_touch_at, $stale)) {
                $playlist->markTouched();
            }

            return $response;
        }

        $provider = $request->route('provider');
        if ($provider instanceof Provider && $provider->exists) {
            if ($this->isStale($provider->last_touch_at, $stale)) {
                $provider->markTouched();
            }

            return $response;
        }

        if ($request->routeIs('playlists.index', 'playlists.data')) {
            Playlist::touchAllForUser((int) $user->id, $stale);
        } elseif ($request->routeIs('providers.index', 'providers.data')) {
            Provider::touchAllForUser((int) $user->id, $stale);
        }

        return $response;
    }

    private function isStale(mixed $lastTouchAt, CarbonInterface $cutoff): bool
    {
        return $lastTouchAt === null || $lastTouchAt->lt($cutoff);
    }
}
