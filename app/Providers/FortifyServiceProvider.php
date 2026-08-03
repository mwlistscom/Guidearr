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
            $cfg = config('guidearr.auth_limits');
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return [
                // Per account: slows guessing at one person's password.
                Limit::perMinute($cfg['login_per_account'])->by($throttleKey),
                // Per address: the per-account key alone lets one host spray an unlimited
                // number of DIFFERENT addresses — every attempt gets its own bucket. This
                // is the limit that actually costs a credential-stuffing run something.
                Limit::perMinute($cfg['login_per_ip'])->by('login-ip|'.$request->ip()),
            ];
        });

        // Registration is rare for a real person and automated in bulk by everyone else —
        // the sign-up form took 21 hits in 60 seconds from one host on 2026-08-01.
        RateLimiter::for('register', function (Request $request) {
            $cfg = config('guidearr.auth_limits');

            return [
                Limit::perMinute($cfg['register_per_minute'])->by('register|'.$request->ip()),
                Limit::perHour($cfg['register_per_hour'])->by('register|'.$request->ip()),
            ];
        });

        // Reset-link requests send mail to an address the requester supplies, so an
        // unlimited endpoint is a way to flood somebody else's inbox from here. Limited
        // per address as well as per host so one target can't be hit from many hosts.
        RateLimiter::for('password-email', function (Request $request) {
            $cfg = config('guidearr.auth_limits');
            $email = Str::transliterate(Str::lower((string) $request->input('email')));

            return [
                Limit::perMinute($cfg['password_email_per_ip'])->by('pwmail-ip|'.$request->ip()),
                Limit::perHour($cfg['password_email_per_account'])->by('pwmail-acct|'.$email),
            ];
        });

        // Social sign-in callback. Deliberately loose: this endpoint signs EXISTING users in
        // as well as provisioning new ones, so a shared office/carrier address must not lock
        // its users out of signing in. Bulk account creation is capped separately, inside
        // OAuthController, on the new-account branch only.
        RateLimiter::for('oauth', function (Request $request) {
            $cfg = config('guidearr.auth_limits');

            return Limit::perMinute($cfg['oauth_callback_per_ip'])->by('oauth|'.$request->ip());
        });

        // Guessing a reset token.
        RateLimiter::for('password-update', function (Request $request) {
            $cfg = config('guidearr.auth_limits');

            return Limit::perMinute($cfg['password_update_per_ip'])->by('pwreset|'.$request->ip());
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
