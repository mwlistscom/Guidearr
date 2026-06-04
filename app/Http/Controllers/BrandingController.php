<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class BrandingController extends Controller
{
    /** The two brand assets and their bundled fallbacks. */
    private const KINDS = [
        'icon' => 'branding/icon-default.png',   // small square mark (sidebar/header/favicon)
        'logo' => 'branding/logo-default.png',   // wide wordmark (landing hero)
    ];

    /** Serve a brand asset (uploaded override, else the bundled default). Public. */
    public function show(string $kind = 'icon')
    {
        $kind = $this->normalizeKind($kind);

        $path = $this->overridePath($kind) ?: public_path(self::KINDS[$kind]);

        if (! is_file($path)) {
            abort(404);
        }

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            'svg'         => 'image/svg+xml',
            default       => 'application/octet-stream',
        };

        return response()->file($path, [
            'Content-Type'  => $mime,
            // revalidate so a freshly uploaded asset shows up without a hard refresh
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    public function edit()
    {
        return view('admin.branding', [
            'hasCustomIcon' => (bool) $this->overridePath('icon'),
            'hasCustomLogo' => (bool) $this->overridePath('logo'),
            'copyright'     => self::copyright(),
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

        foreach (glob($dir . '/' . $kind . '.*') ?: [] as $old) {
            @unlink($old);
        }

        $file = $request->file($kind);
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $file->move($dir, $kind . '.' . $ext);

        return back()->with('status', ucfirst($kind) . ' updated.');
    }

    public function reset(string $kind)
    {
        $kind = $this->normalizeKind($kind);

        foreach (glob($this->storageDir() . '/' . $kind . '.*') ?: [] as $f) {
            @unlink($f);
        }

        return back()->with('status', ucfirst($kind) . ' reset to the default.');
    }

    /** Footer copyright holder text (editable on the Branding page). */
    public static function copyright(): string
    {
        $file = storage_path('app/branding/copyright.txt');

        if (is_file($file)) {
            $val = trim((string) file_get_contents($file));
            if ($val !== '') {
                return $val;
            }
        }

        return 'Jules Potvin';
    }

    public function updateCopyright(Request $request)
    {
        $validated = $request->validate([
            'copyright' => ['required', 'string', 'max:255'],
        ]);

        $text = trim(preg_replace('/[\r\n]+/', ' ', $validated['copyright']));

        @file_put_contents($this->storageDir() . '/copyright.txt', $text, LOCK_EX);

        return back()->with('status', 'Footer copyright updated.');
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
        $matches = glob(storage_path('app/branding') . '/' . $kind . '.*') ?: [];

        return $matches[0] ?? null;
    }
}
