<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class BrandingController extends Controller
{
    /** Copyright holder — the project is owned by, and licensed by, this person. */
    public const OWNER = 'Jules Potvin';

    /** One-line licence summary shown in the footer and admin panel. Full terms live in the repo LICENSE file. */
    public const LICENSE_SUMMARY = 'Free for personal and non-profit use. Commercial or for-profit use is prohibited without written permission.';

    /** The two brand assets and their bundled fallbacks. */
    private const KINDS = [
        'icon' => 'branding/icon-default.png',   // small square mark (sidebar/header/favicon)
        'logo' => 'branding/logo-default.png',   // wide wordmark (landing hero)
    ];

    /**
     * Serve a brand asset (uploaded override, else the bundled default). Public.
     *
     * These are the heaviest things the app serves — a brand icon is typically a
     * multi-hundred-KB PNG rendered at 36px — and the header, sidebar, favicon and
     * every auth screen ask for one on each page view. `no-cache` is deliberate so a
     * freshly uploaded asset appears without a hard refresh, but on its own it means
     * every revalidation re-sends the whole file: Laravel sets Last-Modified and then
     * nothing ever compares it, so a browser's If-Modified-Since was answered with a
     * full 200. On this install that was 792 MB in six days and not one 304.
     *
     * Attaching an ETag and honouring the conditional turns each of those into a
     * ~200-byte 304 while keeping the revalidate-always behaviour intact.
     */
    public function show(Request $request, string $kind = 'icon')
    {
        $kind = $this->normalizeKind($kind);

        $path = $this->overridePath($kind) ?: public_path(self::KINDS[$kind]);

        if (! is_file($path)) {
            abort(404);
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        $response = response()->file($path, [
            'Content-Type' => $mime,
            // revalidate so a freshly uploaded asset shows up without a hard refresh
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);

        // Cheap, stable validator: an upload rewrites the file, changing both.
        $response->setEtag(md5((string) @filemtime($path).'-'.(string) @filesize($path)));

        // Answer If-None-Match / If-Modified-Since with a 304 instead of the whole file.
        $response->isNotModified($request);

        return $response;
    }

    public function edit()
    {
        return view('admin.branding', [
            'hasCustomIcon' => (bool) $this->overridePath('icon'),
            'hasCustomLogo' => (bool) $this->overridePath('logo'),
            'copyright' => self::copyright(),
            'license' => self::LICENSE_SUMMARY,
        ]);
    }

    public function update(Request $request, string $kind)
    {
        $kind = $this->normalizeKind($kind);

        $request->validate([
            // `image` excludes SVG, avoiding inline-script risk on a publicly served file
            $kind => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:10240'],
        ]);

        $dir = $this->storageDir();

        foreach (glob($dir.'/'.$kind.'.*') ?: [] as $old) {
            @unlink($old);
        }

        $file = $request->file($kind);
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $file->move($dir, $kind.'.'.$ext);

        return back()->with('status', ucfirst($kind).' updated.');
    }

    public function reset(string $kind)
    {
        $kind = $this->normalizeKind($kind);

        foreach (glob($this->storageDir().'/'.$kind.'.*') ?: [] as $f) {
            @unlink($f);
        }

        return back()->with('status', ucfirst($kind).' reset to the default.');
    }

    /** Footer copyright holder. Fixed — the project is owned by its author and licensed non-commercially. */
    public static function copyright(): string
    {
        return self::OWNER;
    }

    private function normalizeKind(string $kind): string
    {
        $kind = strtolower($kind);

        return array_key_exists($kind, self::KINDS) ? $kind : 'icon';
    }

    private function storageDir(): string
    {
        $dir = storage_path('app/branding');
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        return $dir;
    }

    private function overridePath(string $kind): ?string
    {
        $matches = glob(storage_path('app/branding').'/'.$kind.'.*') ?: [];

        return $matches[0] ?? null;
    }
}
