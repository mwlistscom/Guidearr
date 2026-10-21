<?php

namespace App\Http\Responses;

use App\Support\PendingRegistration;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

/**
 * Fortify signs a new user in before building this response. Undo that and let the session carry
 * the sign-up instead (PendingRegistration), so a real sign-up and a refused one for an address
 * that already has an account leave the browser in the same state.
 */
class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request)
    {
        $user = $request->user();

        Auth::guard('web')->logout();

        if ($user) {
            PendingRegistration::rememberUser($request, $user);
        }

        return PendingRegistration::response($request);
    }
}
