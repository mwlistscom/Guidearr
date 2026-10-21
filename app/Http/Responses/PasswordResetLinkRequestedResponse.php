<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * One answer to "send me a reset link", whatever happened.
 *
 * Fortify answers "We can't find a user with that email address." for an unknown address and
 * "Please wait before retrying." for a known one asked twice in a minute — so the form told
 * anyone which addresses have an account here. Both outcomes, and success, now read the same.
 */
class PasswordResetLinkRequestedResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function toResponse($request)
    {
        $message = __('If an account exists for that email address, a password reset link is on its way.');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
