<?php

namespace App\Providers;

use App\Http\Controllers\BrandingController;
use App\Support\SocialConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
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
        $this->configureDefaults();
        $this->pinRootUrl();

        View::share('appCopyright', BrandingController::copyright());

        // Feed the admin-configured, encrypted OAuth credentials into config('services.*')
        // so Socialite reads them without any .env involvement.
        SocialConfig::hydrateServices();
    }

    /**
     * Build every absolute URL from APP_URL, never from the request.
     *
     * The host a request arrives with is the client's to choose — the Host header, or
     * X-Forwarded-Host through a proxy — and Laravel builds links from it by default. That
     * includes the link in a password-reset email: anyone could request a reset for any
     * account with their own host in the header, and the genuine email would carry the
     * victim's reset token to the attacker's site. APP_URL is already the canonical address
     * (the session cookie's Secure flag derives from it), so pin links to it as well.
     */
    protected function pinRootUrl(): void
    {
        $root = rtrim((string) config('app.url'), '/');
        $scheme = parse_url($root, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true) || ! parse_url($root, PHP_URL_HOST)) {
            return;
        }

        URL::forceRootUrl($root);
        URL::forceScheme($scheme);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
