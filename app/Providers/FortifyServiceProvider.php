<?php

namespace App\Providers;

/* @chisel-registration */
use App\Actions\Fortify\CreateNewUser;
/* @end-chisel-registration */
use App\Actions\Fortify\ResetUserPassword;
use App\Listeners\UpdateLastLoginTimestamp;
use App\Models\Ban;
use App\Models\User;
use App\Support\Turnstile;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureLoginPipeline();
        $this->configureViews();
        $this->configureRateLimiting();

        Event::listen(Login::class, UpdateLastLoginTimestamp::class);
    }

    /**
     * Enforce account status (active only) on login.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->email)->first();

            if ($user
                && $user->status === 'active'
                && ! Ban::isBanned($user->email)
                && Hash::check($request->password, $user->password)) {
                return $user;
            }

            return null;
        });
    }

    /**
     * Insert the Turnstile CAPTCHA check as a single step in the login pipeline.
     *
     * The CAPTCHA cannot live in the authenticateUsing() callback: Fortify calls
     * that callback TWICE per login when two-factor is enabled (once in
     * RedirectsIfTwoFactorAuthenticatable to identify the user, once in
     * AttemptToAuthenticate). Turnstile tokens are single-use, so the second
     * verification trips Cloudflare's "timeout-or-duplicate" error. A pipeline
     * pipe runs exactly once, so verifying here is safe.
     *
     * This mirrors Fortify's default login pipeline (see AuthenticatedSession
     * controller's loginPipeline()) with our Turnstile pipe inserted before the
     * credential-checking actions. Turnstile::rules() is a no-op when unconfigured
     * or under the test suite, so this stays enforced only in configured production.
     */
    private function configureLoginPipeline(): void
    {
        Fortify::authenticateThrough(fn (Request $request) => array_filter([
            config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            function (Request $request, $next) {
                Validator::make(
                    $request->only('cf-turnstile-response'),
                    ['cf-turnstile-response' => Turnstile::rules()],
                )->validate();

                return $next($request);
            },
            Features::enabled(Features::twoFactorAuthentication()) ? RedirectsIfTwoFactorAuthenticatable::class : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]));
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        /* @chisel-registration */
        Fortify::createUsersUsing(CreateNewUser::class);
        /* @end-chisel-registration */
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        /* @chisel-email-verification */
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        /* @end-chisel-email-verification */
        /* @chisel-2fa */
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        /* @end-chisel-2fa */
        /* @chisel-password-confirmation */
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        /* @end-chisel-password-confirmation */
        /* @chisel-registration */
        Fortify::registerView(fn () => view('pages::auth.register'));
        /* @end-chisel-registration */
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        /* @chisel-passkeys */
        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
        /* @end-chisel-passkeys */
    }
}
