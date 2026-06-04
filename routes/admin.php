<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminPasswordController;
use App\Http\Controllers\Admin\EnvController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\BrandingController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('guidearr.admin.path', 'admin'))->name('admin.')->group(function () {
    Route::get('login', [AdminAuthController::class, 'create'])->name('login');
    Route::post('login', [AdminAuthController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
    Route::post('logout', [AdminAuthController::class, 'destroy'])->name('logout');

    Route::middleware(['admin'])->group(function () {
        Route::get('password', [AdminPasswordController::class, 'edit'])->name('password.edit');
        Route::put('password', [AdminPasswordController::class, 'update'])->name('password.update');

        Route::middleware('admin.password')->group(function () {
            Route::get('/', [AdminController::class, 'index'])->name('dashboard');

            Route::get('users', [UserController::class, 'index'])->name('users');
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::patch('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
            Route::patch('users/{user}/verify', [UserController::class, 'verify'])->name('users.verify');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

            Route::get('environment', [EnvController::class, 'edit'])->name('environment');
            Route::put('environment', [EnvController::class, 'update'])->name('environment.update');

            Route::get('branding', [BrandingController::class, 'edit'])->name('branding');
            Route::put('branding/copyright', [BrandingController::class, 'updateCopyright'])->name('branding.copyright');
            Route::post('branding/{kind}', [BrandingController::class, 'update'])->whereIn('kind', ['icon', 'logo'])->name('branding.update');
            Route::delete('branding/{kind}', [BrandingController::class, 'reset'])->whereIn('kind', ['icon', 'logo'])->name('branding.reset');
        });
    });
});
