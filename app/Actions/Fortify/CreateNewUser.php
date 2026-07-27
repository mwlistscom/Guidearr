<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Ban;
use App\Models\User;
use App\Support\Turnstile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'cf-turnstile-response' => Turnstile::rules(),
        ])->validate();

        // A banned email cannot re-register (the ban outlives any deleted account).
        if (Ban::isBanned($input['email'] ?? null)) {
            throw ValidationException::withMessages([
                'email' => __('This email address is not permitted to register.'),
            ]);
        }

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        if (config('guidearr.registration_requires_approval')) {
            $user->forceFill(['status' => 'pending'])->save();
        }

        return $user;
    }
}
