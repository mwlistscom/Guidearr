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
    Route::post('providers/{provider}/groups', [\App\Http\Controllers\ProviderController::class, 'addGroup'])->name('providers.groups.add');
    Route::post('providers/{provider}/channels', [\App\Http\Controllers\ProviderController::class, 'addChannel'])->name('providers.channels.add');
    Route::patch('providers/{provider}/channels/{channel}', [\App\Http\Controllers\ProviderController::class, 'updateChannel'])->name('providers.channels.update');
    Route::delete('providers/{provider}/channels/{channel}', [\App\Http\Controllers\ProviderController::class, 'deleteChannel'])->name('providers.channels.delete');
});

require __DIR__.'/settings.php';

require __DIR__.'/admin.php';
