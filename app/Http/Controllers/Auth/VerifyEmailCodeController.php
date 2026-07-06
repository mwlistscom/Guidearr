<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class VerifyEmailCodeController extends Controller
{
    /**
     * Confirm a submitted verification code.
     *
     * Answers JSON for the register-page modal (which drives the whole flow with
     * fetch) and falls back to a redirect for the standalone /email/verify page.
     * Turnstile is intentionally not required here — the caller is already an
     * authenticated, throttled session, and a CAPTCHA token would expire while
     * the user reads their email. Account creation stays Turnstile-protected.
     */
    public function store(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->success($request, __('Your email is already verified — you can log in.'));
        }

        if (! $user->verifyCode((string) $request->input('code'))) {
            $message = __('That code is invalid or has expired.');

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'error' => $message], 422);
            }

            throw ValidationException::withMessages(['code' => $message]);
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

        return $this->success($request, __('Your email is verified — you can now log in.'));
    }

    /**
     * Issue a fresh code, honouring the resend cooldown.
     */
    public function resend(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->success($request, __('Your email is already verified — you can log in.'));
        }

        if (! $user->canResendVerification()) {
            $availableAt = $user->verificationResendAvailableAt();
            $seconds = $availableAt ? max(1, now()->diffInSeconds($availableAt, false)) : 0;
            $message = __('Please wait before requesting another code.');

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => $message,
                    'retry_after' => (int) ceil($seconds),
                ], 429);
            }

            return back()->withErrors(['code' => $message]);
        }

        $user->sendEmailVerificationNotification();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => __('A new code is on its way. It can take a minute — check your spam folder.'),
                'resend_available_in' => User::VERIFICATION_RESEND_MINUTES * 60,
            ]);
        }

        return back()->with('status', __('A new code has been sent. Check your spam folder if it does not arrive.'));
    }

    /**
     * Shared success response: JSON with a login redirect for the modal, or a
     * redirect to the login page (with a status flash) for the plain page.
     */
    private function success(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'verified' => true,
                'message' => $message,
                'redirect' => route('login'),
            ]);
        }

        return redirect()->route('login')->with('status', $message);
    }
}
