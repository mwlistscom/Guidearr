<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Ban;
use App\Models\User;
use App\Notifications\ExistingAccountSignUpAttempt;
use App\Support\PendingRegistration;
use App\Support\Turnstile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Unique;
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
        // No `unique` rule on the email: "already taken" would tell anyone which addresses have
        // an account here. A taken address is handled below, after every other check has passed.
        Validator::make($input, [
            'name' => $this->nameRules(),
            'email' => array_values(array_filter($this->emailRules(), fn ($rule) => ! $rule instanceof Unique)),
            'password' => $this->passwordRules(),
            'cf-turnstile-response' => Turnstile::rules(),
        ])->validate();

        // A banned email cannot re-register (the ban outlives any deleted account).
        if (Ban::isBanned($input['email'] ?? null)) {
            throw ValidationException::withMessages([
                'email' => __('This email address is not permitted to register.'),
            ]);
        }

        $existing = User::where('email', $input['email'])->first();
        if ($existing) {
            $this->answerAsIfCreated($existing, (string) $input['password']);
        }

        try {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Taken between the check above and the insert.
            $this->answerAsIfCreated(User::where('email', $input['email'])->firstOrFail(), (string) $input['password']);
        }

        if (config('guidearr.registration_requires_approval')) {
            $user->forceFill(['status' => 'pending'])->save();
        }

        return $user;
    }

    /**
     * The address already has an account: create nothing, tell its owner by email, and answer
     * the browser exactly as a successful sign-up would (PendingRegistration's stand-in then
     * plays the code screen). Throwing the response is the only way out of Fortify's controller
     * without creating or signing in anyone.
     */
    private function answerAsIfCreated(User $existing, string $password): never
    {
        // A real sign-up hashes the password; do the same work so the reply takes as long.
        Hash::make($password);

        // At most a few notices an hour per address, so the form can't be used to flood an inbox.
        $key = 'signup-existing|'.sha1(strtolower($existing->email));
        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 3600);
            $existing->notify(new ExistingAccountSignUpAttempt);
        }

        $request = request();
        $request->session()->regenerate();
        PendingRegistration::rememberStandIn($request);

        throw new HttpResponseException(PendingRegistration::response($request));
    }
}
