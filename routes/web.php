<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\VerifyEmailCodeController;
use App\Http\Controllers\BrandingController;

Route::view('/', 'hello')->name('home');

Route::get('branding/icon', [BrandingController::class, 'show'])->defaults('kind', 'icon')->name('branding.icon');
Route::get('branding/logo', [BrandingController::class, 'show'])->defaults('kind', 'logo')->name('branding.logo');
Route::view('docs', 'docs')->name('docs');

Route::post('email/verify-code', [VerifyEmailCodeController::class, 'store'])
    ->middleware('auth')
    ->name('verification.code');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::get('providers', [\App\Http\Controllers\ProviderController::class, 'index'])->name('providers.index');
    Route::get('providers/data', [\App\Http\Controllers\ProviderController::class, 'data'])->name('providers.data');

    Route::get('playlists', [\App\Http\Controllers\PlaylistController::class, 'index'])->name('playlists.index');
    Route::get('playlists/data', [\App\Http\Controllers\PlaylistController::class, 'data'])->name('playlists.data');
    Route::get('playlists/options', [\App\Http\Controllers\PlaylistController::class, 'options'])->name('playlists.options');
    Route::post('playlists', [\App\Http\Controllers\PlaylistController::class, 'store'])->name('playlists.store');
    Route::delete('playlists/{playlist}', [\App\Http\Controllers\PlaylistController::class, 'destroy'])->name('playlists.destroy');
    Route::patch('playlists/{playlist}', [\App\Http\Controllers\PlaylistController::class, 'update'])->name('playlists.update');
    Route::post('playlists/{playlist}/rotate-key', [\App\Http\Controllers\PlaylistController::class, 'rotateKey'])->name('playlists.rotateKey');
    Route::get('playlists/{playlist}/channels', [\App\Http\Controllers\PlaylistController::class, 'channels'])->name('playlists.channels');
    Route::get('playlists/{playlist}/groups', [\App\Http\Controllers\PlaylistController::class, 'groups'])->name('playlists.groups');
    Route::post('playlists/{playlist}/groups', [\App\Http\Controllers\PlaylistController::class, 'addGroupRow'])->name('playlists.groups.add');
    Route::post('playlists/{playlist}/channels', [\App\Http\Controllers\PlaylistController::class, 'addChannel'])->name('playlists.channels.add');
    Route::patch('playlists/{playlist}/channels/{cid}', [\App\Http\Controllers\PlaylistController::class, 'updateChannel'])->name('playlists.channels.update');
    Route::post('playlists/{playlist}/channels/{cid}/move', [\App\Http\Controllers\PlaylistController::class, 'moveChannel'])->name('playlists.channels.move');
    Route::delete('playlists/{playlist}/channels/{cid}', [\App\Http\Controllers\PlaylistController::class, 'deleteChannel'])->name('playlists.channels.delete');
    Route::patch('playlists/{playlist}/groups/{gid}', [\App\Http\Controllers\PlaylistController::class, 'updateGroup'])->name('playlists.groups.update');
    Route::post('playlists/{playlist}/groups/{gid}/move', [\App\Http\Controllers\PlaylistController::class, 'moveGroup'])->name('playlists.groups.move');
    Route::delete('playlists/{playlist}/groups/{gid}', [\App\Http\Controllers\PlaylistController::class, 'deleteGroup'])->name('playlists.groups.delete');
    Route::post('playlists/{playlist}/reindex', [\App\Http\Controllers\PlaylistController::class, 'reindex'])->name('playlists.reindex');
    Route::get('playlists/{playlist}/guide', [\App\Http\Controllers\PlaylistController::class, 'guide'])->name('playlists.guide');
    Route::get('providers/feed/{msgid}', [\App\Http\Controllers\ProviderController::class, 'feed'])->name('providers.feed');
    Route::post('providers', [\App\Http\Controllers\ProviderController::class, 'store'])->name('providers.store');
    Route::get('providers/{provider}', [\App\Http\Controllers\ProviderController::class, 'show'])->name('providers.show');
    Route::put('providers/{provider}', [\App\Http\Controllers\ProviderController::class, 'update'])->name('providers.update');
    Route::delete('providers/{provider}', [\App\Http\Controllers\ProviderController::class, 'destroy'])->name('providers.destroy');
    Route::post('providers/{provider}/toggle', [\App\Http\Controllers\ProviderController::class, 'toggle'])->name('providers.toggle');
    Route::patch('providers/{provider}/cell', [\App\Http\Controllers\ProviderController::class, 'updateCell'])->name('providers.cell');
    Route::post('providers/{provider}/refresh', [\App\Http\Controllers\ProviderController::class, 'refresh'])->name('providers.refresh');
    Route::get('providers/{provider}/logs', [\App\Http\Controllers\ProviderController::class, 'logs'])->name('providers.logs');
    Route::get('providers/{provider}/channels', [\App\Http\Controllers\ProviderController::class, 'channels'])->name('providers.channels');
    Route::get('providers/{provider}/groups', [\App\Http\Controllers\ProviderController::class, 'groups'])->name('providers.groups');
    Route::get('providers/{provider}/guide/channels', [\App\Http\Controllers\ProviderController::class, 'guideChannels'])->name('providers.guide.channels');
    Route::get('providers/{provider}/guide/programmes', [\App\Http\Controllers\ProviderController::class, 'guideProgrammes'])->name('providers.guide.programmes');
    Route::post('providers/{provider}/groups', [\App\Http\Controllers\ProviderController::class, 'addGroup'])->name('providers.groups.add');
    Route::post('providers/{provider}/channels', [\App\Http\Controllers\ProviderController::class, 'addChannel'])->name('providers.channels.add');
    Route::patch('providers/{provider}/channels/{channel}', [\App\Http\Controllers\ProviderController::class, 'updateChannel'])->name('providers.channels.update');
    Route::delete('providers/{provider}/channels/{channel}', [\App\Http\Controllers\ProviderController::class, 'deleteChannel'])->name('providers.channels.delete');
});

require __DIR__.'/settings.php';

require __DIR__.'/admin.php';
