<?php

use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Auth\VerifyEmailCodeController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\PlaylistServeController;
use App\Http\Controllers\ProviderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'hello')->name('home');

Route::get('branding/icon', [BrandingController::class, 'show'])->defaults('kind', 'icon')->name('branding.icon');
Route::get('branding/logo', [BrandingController::class, 'show'])->defaults('kind', 'logo')->name('branding.logo');
Route::view('docs', 'docs')->name('docs');
Route::get('license', function (Request $request) {
    return view('license', [
        'text' => @file_get_contents(base_path('LICENSE')) ?: 'License file not found.',
        'back' => $request->query('from') === 'admin' ? route('admin.dashboard') : url('/'),
    ]);
})->name('license');

// Public, editable legal pages (Admin → Legal). Bodies stored in the settings JSON store,
// falling back to the shipped Markdown defaults in resources/legal/.
Route::get('privacy', [LegalController::class, 'show'])->defaults('doc', 'privacy')->name('legal.privacy');
Route::get('terms', [LegalController::class, 'show'])->defaults('doc', 'terms')->name('legal.terms');
Route::get('cookies', [LegalController::class, 'show'])->defaults('doc', 'cookies')->name('legal.cookies');

// Social sign-in (Google / Facebook via Socialite). Buttons appear only when a provider is configured.
Route::get('auth/{provider}/redirect', [OAuthController::class, 'redirect'])->whereIn('provider', ['google', 'facebook'])->name('oauth.redirect');
Route::get('auth/{provider}/callback', [OAuthController::class, 'callback'])->whereIn('provider', ['google', 'facebook'])->name('oauth.callback');
// Meta data-deletion callback (CSRF-exempt — Facebook POSTs a signed_request) + its status page.
Route::post('data-deletion/facebook', [OAuthController::class, 'facebookDataDeletion'])->name('oauth.facebook.data-deletion');
Route::get('data-deletion/facebook/status', [OAuthController::class, 'facebookDataDeletionStatus'])->name('oauth.facebook.data-deletion.status');

// Public playlist serving endpoints (keyed by ?key=<cipher>). No .php extension so
// the Laravel router handles them instead of nginx trying to exec a file on disk.
Route::get('m3u', [PlaylistServeController::class, 'm3u'])->name('serve.m3u');
Route::get('epg', [PlaylistServeController::class, 'epg'])->name('serve.epg');
Route::get('strm', [PlaylistServeController::class, 'strm'])->name('serve.strm');

Route::post('email/verify-code', [VerifyEmailCodeController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('verification.code');

Route::post('email/resend-code', [VerifyEmailCodeController::class, 'resend'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('verification.resend');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
    Route::get('providers/data', [ProviderController::class, 'data'])->name('providers.data');

    Route::get('playlists', [PlaylistController::class, 'index'])->name('playlists.index');
    Route::get('playlists/data', [PlaylistController::class, 'data'])->name('playlists.data');
    Route::get('playlists/options', [PlaylistController::class, 'options'])->name('playlists.options');
    Route::post('playlists', [PlaylistController::class, 'store'])->name('playlists.store');
    Route::delete('playlists/{playlist}', [PlaylistController::class, 'destroy'])->name('playlists.destroy');
    Route::patch('playlists/{playlist}', [PlaylistController::class, 'update'])->name('playlists.update');
    Route::post('playlists/{playlist}/rotate-key', [PlaylistController::class, 'rotateKey'])->name('playlists.rotateKey');
    Route::get('playlists/{playlist}/channels', [PlaylistController::class, 'channels'])->name('playlists.channels');
    Route::get('playlists/{playlist}/groups', [PlaylistController::class, 'groups'])->name('playlists.groups');
    Route::post('playlists/{playlist}/groups', [PlaylistController::class, 'addGroupRow'])->name('playlists.groups.add');
    Route::post('playlists/{playlist}/channels', [PlaylistController::class, 'addChannel'])->name('playlists.channels.add');
    Route::patch('playlists/{playlist}/channels/{cid}', [PlaylistController::class, 'updateChannel'])->name('playlists.channels.update');
    Route::post('playlists/{playlist}/channels/move-bulk', [PlaylistController::class, 'moveChannelsBulk'])->name('playlists.channels.move-bulk');
    Route::post('playlists/{playlist}/channels/{cid}/move', [PlaylistController::class, 'moveChannel'])->name('playlists.channels.move');
    Route::delete('playlists/{playlist}/channels/{cid}', [PlaylistController::class, 'deleteChannel'])->name('playlists.channels.delete');
    Route::patch('playlists/{playlist}/groups/{gid}', [PlaylistController::class, 'updateGroup'])->name('playlists.groups.update');
    Route::post('playlists/{playlist}/groups/{gid}/move', [PlaylistController::class, 'moveGroup'])->name('playlists.groups.move');
    Route::delete('playlists/{playlist}/groups/{gid}', [PlaylistController::class, 'deleteGroup'])->name('playlists.groups.delete');
    Route::post('playlists/{playlist}/reindex', [PlaylistController::class, 'reindex'])->name('playlists.reindex');
    Route::get('playlists/{playlist}/guide', [PlaylistController::class, 'guide'])->name('playlists.guide');
    Route::get('providers/feed/{msgid}', [ProviderController::class, 'feed'])->name('providers.feed');
    Route::post('providers', [ProviderController::class, 'store'])->name('providers.store');
    Route::get('providers/{provider}', [ProviderController::class, 'show'])->name('providers.show');
    Route::put('providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');
    Route::delete('providers/{provider}', [ProviderController::class, 'destroy'])->name('providers.destroy');
    Route::post('providers/{provider}/toggle', [ProviderController::class, 'toggle'])->name('providers.toggle');
    Route::patch('providers/{provider}/cell', [ProviderController::class, 'updateCell'])->name('providers.cell');
    Route::post('providers/{provider}/refresh', [ProviderController::class, 'refresh'])->name('providers.refresh');
    Route::get('providers/{provider}/logs', [ProviderController::class, 'logs'])->name('providers.logs');
    Route::get('providers/{provider}/channels', [ProviderController::class, 'channels'])->name('providers.channels');
    Route::get('providers/{provider}/groups', [ProviderController::class, 'groups'])->name('providers.groups');
    Route::get('providers/{provider}/guide/channels', [ProviderController::class, 'guideChannels'])->name('providers.guide.channels');
    Route::get('providers/{provider}/guide/programmes', [ProviderController::class, 'guideProgrammes'])->name('providers.guide.programmes');
    Route::post('providers/{provider}/groups', [ProviderController::class, 'addGroup'])->name('providers.groups.add');
    Route::post('providers/{provider}/channels', [ProviderController::class, 'addChannel'])->name('providers.channels.add');
    Route::patch('providers/{provider}/channels/{channel}', [ProviderController::class, 'updateChannel'])->name('providers.channels.update');
    Route::delete('providers/{provider}/channels/{channel}', [ProviderController::class, 'deleteChannel'])->name('providers.channels.delete');
});

require __DIR__.'/settings.php';

require __DIR__.'/admin.php';
