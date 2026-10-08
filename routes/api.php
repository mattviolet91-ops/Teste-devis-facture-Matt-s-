<?php

use App\Http\Controllers\Api\ArgentApiController;
use App\Http\Controllers\Api\QuoteApiController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

// API (préfixe /api) : création de devis brouillons par Claude, avec une clé d'accès du gérant.
Route::prefix('v1')->middleware([AuthenticateApiToken::class, 'throttle:30,1'])->group(function () {
    Route::get('/clients', [QuoteApiController::class, 'clients']);
    Route::get('/prestations', [QuoteApiController::class, 'catalog']);
    Route::post('/devis/apercu', [QuoteApiController::class, 'preview']);
    Route::post('/devis', [QuoteApiController::class, 'store']);
    Route::post('/devis/complet', [QuoteApiController::class, 'storeStructured']);
});

// App Argent (application séparée) : lecture seule des paiements reçus, des frais et des montants en attente.
Route::prefix('v1')->middleware([AuthenticateApiToken::class.':argent', 'throttle:30,1'])->group(function () {
    Route::get('/argent', ArgentApiController::class);
});
