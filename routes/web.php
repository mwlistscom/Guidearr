<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\VerifyEmailCodeController;
use App\Http\Controllers\BrandingController;

Route::view('/', 'hello')->name('home');

Route::get('branding/icon', [BrandingController::class, 'show'])->name('branding.icon');
Route::view('docs', 'docs')->name('docs');

Route::post('email/verify-code', [VerifyEmailCodeController::class, 'store'])
    ->middleware('auth')
    ->name('verification.code');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

require __DIR__.'/admin.php';
