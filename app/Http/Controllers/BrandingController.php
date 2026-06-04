<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BrandingController extends Controller
{
    /** Serve the active app icon (uploaded override, else the bundled default). Public. */
    public function show()
    {
        $path = $this->overridePath() ?: public_path('branding/default.png');

        if (! is_file($path)) {
            abort(404);
        }

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            default       => 'application/octet-stream',
        };

        return response()->file($path, [
            'Content-Type'  => $mime,
            // revalidate so a freshly uploaded icon shows up without a hard refresh
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    public function edit()
    {
        return view('admin.branding', ['hasCustom' => (bool) $this->overridePath()]);
    }

    public function update(Request $request)
    {
        $request->validate([
            // `image` excludes SVG, avoiding inline-script risk on a publicly served file
            'icon' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048'],
        ]);

        $dir = storage_path('app/branding');
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        foreach (glob($dir . '/icon.*') ?: [] as $old) {
            @unlink($old);
        }

        $ext = strtolower($request->file('icon')->getClientOriginalExtension()
            ?: $request->file('icon')->extension());
        $request->file('icon')->move($dir, 'icon.' . $ext);

        return back()->with('status', 'App icon updated.');
    }

    public function reset()
    {
        foreach (glob(storage_path('app/branding') . '/icon.*') ?: [] as $f) {
            @unlink($f);
        }

        return back()->with('status', 'App icon reset to the default.');
    }

    private function overridePath(): ?string
    {
        $matches = glob(storage_path('app/branding') . '/icon.*') ?: [];

        return $matches[0] ?? null;
    }
}
