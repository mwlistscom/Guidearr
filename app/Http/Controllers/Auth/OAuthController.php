<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\SocialLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    /** Send the user to the provider's consent screen. */
    public function redirect(string $provider)
    {
        abort_unless(SocialLogin::enabled($provider), 404);

        return $this->driver($provider)->redirect();
    }

    /** Handle the provider callback: find-or-create-or-link, then sign in. */
    public function callback(Request $request, string $provider)
    {
        abort_unless(SocialLogin::enabled($provider), 404);
        $label = SocialLogin::label($provider);

        try {
            $oauthUser = $this->driver($provider)->user();
        } catch (\Throwable $e) {
            $to = $request->user() ? 'connected-accounts.edit' : 'login';

            return redirect()->route($to)->withErrors(['email' => "Could not connect {$label}. Please try again."]);
        }

        // A signed-in user is connecting an additional provider from Settings.
        if ($request->user()) {
            return $this->linkToCurrentUser($request->user(), $provider, $oauthUser, $label);
        }

        if (! $oauthUser->getEmail()) {
            return redirect()->route('login')->withErrors(['email' => "{$label} did not share an email address, which is required to sign in."]);
        }

        $user = $this->resolveUser($provider, $oauthUser);

        // Auth::login() bypasses Fortify's login pipeline, so re-apply its guards here.
        if ($user->status !== 'active') {
            return redirect()->route('login')->withErrors(['email' => 'Your account is not active yet — an administrator must approve it first.']);
        }
        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            return redirect()->route('login')->withErrors(['email' => 'This account has two-factor authentication enabled. Please sign in with your email and password.']);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /** Find the linked account, else link to a same-email user, else create a verified user. */
    private function resolveUser(string $provider, SocialiteUser $oauthUser): User
    {
        $providerId = (string) $oauthUser->getId();

        $account = SocialAccount::where('provider', $provider)->where('provider_id', $providerId)->first();
        if ($account) {
            $this->syncAvatar($account, $oauthUser);

            return $account->user;
        }

        $email = $oauthUser->getEmail();
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = new User;
            $user->name = $oauthUser->getName() ?: Str::before($email, '@');
            $user->email = $email;
            $user->email_verified_at = now();           // the provider already verified the address
            // Set status explicitly (don't rely on the DB default) so the in-memory model is
            // correct for the login guards below. Mirrors the email/password registration policy.
            $user->status = config('guidearr.registration_requires_approval') ? 'pending' : 'active';
            $user->save();                              // password stays null (social-only account)
        }

        $user->socialAccounts()->create([
            'provider' => $provider,
            'provider_id' => $providerId,
            'avatar' => $oauthUser->getAvatar(),
        ]);

        return $user;
    }

    /** Connect a provider to the already-signed-in user (from the Connected accounts page). */
    private function linkToCurrentUser(User $user, string $provider, SocialiteUser $oauthUser, string $label)
    {
        $providerId = (string) $oauthUser->getId();
        $existing = SocialAccount::where('provider', $provider)->where('provider_id', $providerId)->first();

        if ($existing && $existing->user_id !== $user->id) {
            return redirect()->route('connected-accounts.edit')
                ->withErrors(['provider' => "That {$label} account is already connected to a different user."]);
        }
        if (! $existing) {
            $user->socialAccounts()->create([
                'provider' => $provider,
                'provider_id' => $providerId,
                'avatar' => $oauthUser->getAvatar(),
            ]);
        }

        return redirect()->route('connected-accounts.edit')->with('status', "{$label} connected.");
    }

    private function syncAvatar(SocialAccount $account, SocialiteUser $oauthUser): void
    {
        $avatar = $oauthUser->getAvatar();
        if ($avatar && $avatar !== $account->avatar) {
            $account->update(['avatar' => $avatar]);
        }
    }

    /** Same callback URL used for the authorize request and the token exchange. */
    private function driver(string $provider)
    {
        $redirect = config("services.{$provider}.redirect") ?: route('oauth.callback', $provider);

        return Socialite::driver($provider)->redirectUrl($redirect);
    }

    /**
     * Meta Data-Deletion Request callback. Facebook POSTs a signed_request when a user removes the
     * app; we verify it with the app secret, unlink that Facebook identity, and return a status URL
     * + confirmation code as required. https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback
     */
    public function facebookDataDeletion(Request $request)
    {
        $secret = (string) config('services.facebook.client_secret');
        $data = $this->parseSignedRequest((string) $request->input('signed_request'), $secret);
        if ($data === null || empty($data['user_id'])) {
            return response()->json(['error' => 'Invalid signed_request'], 400);
        }

        // Remove the data obtained from Facebook (the linked identity).
        SocialAccount::where('provider', 'facebook')->where('provider_id', (string) $data['user_id'])->delete();

        $code = Str::lower(Str::random(16));

        return response()->json([
            'url' => route('oauth.facebook.data-deletion.status', ['code' => $code]),
            'confirmation_code' => $code,
        ]);
    }

    /** Public status page Facebook links the user to after a deletion request. */
    public function facebookDataDeletionStatus(Request $request)
    {
        return response('Data deletion request received and processed. Confirmation code: '
            .preg_replace('/[^a-z0-9]/', '', (string) $request->query('code')), 200)
            ->header('Content-Type', 'text/plain');
    }

    /** Verify + decode Facebook's base64url `signed_request` (payload.signature, HMAC-SHA256). */
    private function parseSignedRequest(string $signed, string $secret): ?array
    {
        if ($secret === '' || ! str_contains($signed, '.')) {
            return null;
        }
        [$sig, $payload] = explode('.', $signed, 2);
        $decode = fn (string $s) => base64_decode(strtr($s, '-_', '+/'));
        $expected = hash_hmac('sha256', $payload, $secret, true);
        if (! hash_equals($expected, (string) $decode($sig))) {
            return null;
        }
        $data = json_decode((string) $decode($payload), true);

        return is_array($data) ? $data : null;
    }
}
