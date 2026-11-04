<?php

use App\Http\Controllers\CustomerTrafficController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
|
| The monitoring traffic read endpoints keep the existing browser-session
| contract while moving into the versioned API route file. The `web` group is
| intentional: this family is called by the Inertia admin panel, not by a
| bearer-token machine client.
|
*/
Route::prefix('v1')
    ->middleware(['web', 'auth', 'verified'])
    ->group(function (): void {
        Route::get('/monitoring/traffic', [CustomerTrafficController::class, 'index'])
            ->middleware('throttle:api')
            ->name('api.monitoring.traffic.index');
        Route::get('/monitoring/traffic/options', [CustomerTrafficController::class, 'options'])
            ->middleware('throttle:api')
            ->name('api.monitoring.traffic.options');
    });
