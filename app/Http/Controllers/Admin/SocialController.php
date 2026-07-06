<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use App\Support\SocialConfig;
use App\Support\SocialLogin;
use Illuminate\Http\Request;

class SocialController extends Controller
{
    public function edit()
    {
        $providers = [];
        foreach (SocialLogin::PROVIDERS as $p) {
            $c = SocialConfig::provider($p);
            $providers[$p] = [
                'label' => SocialLogin::label($p),
                'enabled' => $c['enabled'],
                'client_id' => $c['client_id'],
                'has_secret' => $c['client_secret'] !== '',
                'redirect' => $c['redirect'],
            ];
        }

        return view('admin.social', [
            'providers' => $providers,
            'urls' => SocialConfig::callbackUrls(),
            'linksBaseSet' => Settings::linksBaseUrl() !== '',
        ]);
    }

    public function update(Request $request)
    {
        foreach (SocialLogin::PROVIDERS as $p) {
            $in = (array) $request->input($p, []);
            $enabled = (bool) ($in['enabled'] ?? false);
            $clientId = trim((string) ($in['client_id'] ?? ''));
            $secretIn = trim((string) ($in['client_secret'] ?? ''));
            $redirect = trim((string) ($in['redirect'] ?? ''));

            foreach ([$clientId, $secretIn, $redirect] as $v) {
                if (preg_match('/[\r\n]/', $v)) {
                    return back()->withErrors([$p => 'Values must not contain line breaks.'])->withInput();
                }
            }

            // Enabling requires a Client ID and a secret (either newly entered or already stored).
            if ($enabled && ($clientId === '' || ($secretIn === '' && ! SocialConfig::hasSecret($p)))) {
                return back()
                    ->withErrors([$p => SocialLogin::label($p).' needs a Client ID and Client Secret before it can be enabled.'])
                    ->withInput();
            }

            SocialConfig::save($p, [
                'enabled' => $enabled,
                'client_id' => $clientId,
                'client_secret' => $secretIn,
                'redirect' => $redirect,
            ]);
        }

        return redirect()->route('admin.social')->with('status', 'Social sign-in settings saved.');
    }
}
