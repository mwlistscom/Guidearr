<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Support\Turnstile;

class VerifyEmailCodeController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string'],
            'cf-turnstile-response' => Turnstile::rules(),
        ]);

        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        if (! $user->verifyCode((string) $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => __('That code is invalid or has expired.'),
            ]);
        }

        $user->markEmailAsVerified();
        $user->forceFill([
            'verification_code' => null,
            'verification_code_expires_at' => null,
        ])->save();

        // Log out so the user signs in fresh, per the desired flow.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')
            ->with('status', __('Your email is verified — you can now log in.'));
    }
}
