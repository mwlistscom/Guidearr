<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ban;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Features;
use App\Support\Turnstile;

class AdminAuthController extends Controller
{
    public function create()
    {
        if (Auth::check() && Auth::user()->is_admin) {
            return redirect()->route('admin.dashboard');
        }
        return view('admin.login');
    }

    public function store(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'cf-turnstile-response' => Turnstile::rules(),
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! $user->is_admin || $user->status !== 'active'
            || Ban::isBanned($user->email)
            || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('Those credentials do not match an active admin account.'),
            ]);
        }

        // An admin with two-factor enabled must pass the same challenge as at /login. This form
        // used to log straight in after the password, and it shares the `web` guard, so a
        // phished or reused password was a full admin session with 2FA never asked for. Hand
        // over exactly as Fortify's own login does (login.id / login.remember), with the admin
        // dashboard as the intended destination once the code is accepted.
        if (Features::enabled(Features::twoFactorAuthentication())
            && $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $request->boolean('remember'),
            ]);
            redirect()->setIntendedUrl(route('admin.dashboard'));

            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('admin.login');
    }
}
