<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The sign-up in progress in this browser, between "Create account" and entering the emailed code.
 *
 * Registration must look the same whether or not the address already has an account, or the form
 * becomes a way to test which addresses are registered here. So it signs NOBODY in — a signed-in
 * session after a real sign-up (and none after a refused one) is just as telling as an error
 * message. Instead the session remembers the sign-up: the new user's id, or, when the address was
 * taken, a stand-in that answers the code and resend endpoints exactly as a real one would, except
 * that no code ever matches.
 */
class PendingRegistration
{
    private const KEY = 'registration.pending';

    /** How long the code screen keeps working for one sign-up. */
    private const TTL_SECONDS = 3600;

    public static function rememberUser(Request $request, User $user): void
    {
        $request->session()->put(self::KEY, ['user_id' => $user->getKey(), 'at' => now()->getTimestamp()]);
    }

    /** A sign-up for an address that already has an account: nothing was created. */
    public static function rememberStandIn(Request $request): void
    {
        $request->session()->put(self::KEY, [
            'user_id' => null,
            'at' => now()->getTimestamp(),
            'sent_at' => now()->getTimestamp(),
            'attempts' => 0,
        ]);
    }

    /** The user whose sign-up this session is completing, if any. */
    public static function user(Request $request): ?User
    {
        $pending = self::current($request);

        return $pending && $pending['user_id'] ? User::find($pending['user_id']) : null;
    }

    public static function isStandIn(Request $request): bool
    {
        $pending = self::current($request);

        return $pending !== null && $pending['user_id'] === null;
    }

    /**
     * A wrong code against the stand-in. Mirrors User::verifyCode(): the same messages, and the
     * same "too many incorrect codes" after the same number of tries.
     */
    public static function standInWrongCode(Request $request): string
    {
        $pending = self::current($request);
        $pending['attempts'] = ($pending['attempts'] ?? 0) + 1;
        $request->session()->put(self::KEY, $pending);

        return $pending['attempts'] >= User::VERIFICATION_MAX_ATTEMPTS
            ? __('Too many incorrect codes. Request a new code and try again.')
            : __('That code is invalid or has expired.');
    }

    /** Seconds until the stand-in may "send" another code, or 0 if it may now. Mirrors the resend cooldown. */
    public static function standInResendWait(Request $request): int
    {
        $sentAt = (int) (self::current($request)['sent_at'] ?? 0);

        return max(0, $sentAt + User::VERIFICATION_RESEND_MINUTES * 60 - now()->getTimestamp());
    }

    /** The stand-in "sent" a fresh code: reset the clock and the guess count, as a real resend does. */
    public static function standInResent(Request $request): void
    {
        $pending = self::current($request);
        $pending['sent_at'] = now()->getTimestamp();
        $pending['attempts'] = 0;
        $request->session()->put(self::KEY, $pending);
    }

    /** The one answer to a sign-up, whatever happened behind it. */
    public static function response(Request $request): mixed
    {
        return $request->wantsJson()
            ? new JsonResponse('', 201)
            : redirect()->route('login')->with('status', __('Check your email for a verification code, then sign in to enter it.'));
    }

    private static function current(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $pending = $request->session()->get(self::KEY);

        if (! is_array($pending) || (now()->getTimestamp() - (int) ($pending['at'] ?? 0)) > self::TTL_SECONDS) {
            return null;
        }

        return $pending;
    }
}
