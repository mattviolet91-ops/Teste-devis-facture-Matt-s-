<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BrandingAssetController;
use App\Http\Controllers\ComingSoonController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Settings;
use Illuminate\Support\Facades\Route;

Route::get('/marque/logo', [BrandingAssetController::class, 'logo'])->name('branding.logo');

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [LoginController::class, 'create'])->name('login');
    Route::post('/connexion', [LoginController::class, 'store'])->middleware('throttle:20,1');

    Route::get('/mot-de-passe-oublie', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/mot-de-passe-oublie', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reinitialiser/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reinitialiser', [PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::prefix('reglages')->name('settings.')->group(function () {
        Route::redirect('/', '/reglages/entreprise')->name('index');

        Route::get('/entreprise', [Settings\CompanyController::class, 'edit'])->name('company');
        Route::put('/entreprise', [Settings\CompanyController::class, 'update']);

        Route::get('/apparence', [Settings\BrandingController::class, 'edit'])->name('branding');
        Route::post('/apparence', [Settings\BrandingController::class, 'update']);
        Route::post('/apparence/reinitialiser', [Settings\BrandingController::class, 'reset'])->name('branding.reset');

        Route::get('/tva', [Settings\VatController::class, 'edit'])->name('vat');
        Route::put('/tva/regime', [Settings\VatController::class, 'updateRegime'])->name('vat.regime');
        Route::post('/tva/taux', [Settings\VatController::class, 'storeRate'])->name('vat.rates.store');
        Route::put('/tva/taux/{rate}', [Settings\VatController::class, 'updateRate'])->name('vat.rates.update');
        Route::post('/tva/unites', [Settings\VatController::class, 'storeUnit'])->name('units.store');
        Route::put('/tva/unites/{unit}', [Settings\VatController::class, 'updateUnit'])->name('units.update');

        Route::get('/numerotation', [Settings\NumberingController::class, 'edit'])->name('numbering');
        Route::put('/numerotation', [Settings\NumberingController::class, 'update']);

        Route::get('/compte', [Settings\AccountController::class, 'edit'])->name('account');
        Route::put('/compte/profil', [Settings\AccountController::class, 'updateProfile'])->name('account.profile');
        Route::put('/compte/mot-de-passe', [Settings\AccountController::class, 'updatePassword'])->name('account.password');
    });

    Route::get('/{module}', ComingSoonController::class)
        ->whereIn('module', array_keys(ComingSoonController::MODULES))
        ->name('module');
});
