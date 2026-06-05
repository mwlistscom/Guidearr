<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Checks the GitHub Releases API for a newer published version. Result is cached
 * for 6h; failures are never cached (so a transient outage retries next load).
 * Disable entirely with GUIDEARR_UPDATE_CHECK=false.
 */
class ReleaseCheck
{
    private const CACHE_KEY = 'guidearr:latest_release';

    /**
     * @return array{current:string,latest:string,url:string,available:bool}|null
     *         null when disabled, offline, or the latest tag can't be determined.
     */
    public static function status(): ?array
    {
        if (! config('guidearr.update_check', true)) {
            return null;
        }

        $latest = self::latest();
        if ($latest === null) {
            return null;
        }

        $current = (string) config('guidearr.version');
        $lv = ltrim($latest['tag'], 'vV');

        return [
            'current'   => $current,
            'latest'    => $lv,
            'url'       => $latest['url'],
            'available' => $current !== 'dev' && version_compare($lv, ltrim($current, 'vV'), '>'),
        ];
    }

    /** @return array{tag:string,url:string}|null */
    private static function latest(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $repo = (string) config('guidearr.github_repo', 'mwlistscom/Guidearr');

        try {
            $res = Http::timeout(4)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Guidearr'])
                ->get("https://api.github.com/repos/{$repo}/releases/latest");

            if (! $res->ok() || ! $res->json('tag_name')) {
                return null; // not cached — retry next time
            }

            $out = [
                'tag' => (string) $res->json('tag_name'),
                'url' => (string) ($res->json('html_url') ?: "https://github.com/{$repo}/releases"),
            ];
            Cache::put(self::CACHE_KEY, $out, now()->addHours(6));

            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
