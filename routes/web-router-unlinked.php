<?php

use App\Http\Controllers\RouterUnlinkedSecretController;
use App\Http\Controllers\RouterUnlinkedSecretDeleteController;
use Illuminate\Support\Facades\Route;

// Dipanggil dari dalam grup `global-admin` di routes/web.php.
Route::get('/api/routers/{router}/unlinked-secrets', [RouterUnlinkedSecretController::class, 'index'])
    ->name('api.routers.unlinked-secrets.index');

// Hapus secret (hanya superadmin + NOC dicek di controller), dibatasi 10 permintaan per menit.
Route::middleware('throttle:10,1')->scopeBindings()->group(function () {
    Route::get('/api/routers/{router}/unlinked-secrets/{stagedCustomer}/delete-check', [RouterUnlinkedSecretDeleteController::class, 'check'])
        ->name('api.routers.unlinked-secrets.delete-check');
    Route::delete('/api/routers/{router}/unlinked-secrets/{stagedCustomer}', [RouterUnlinkedSecretDeleteController::class, 'destroy'])
        ->name('api.routers.unlinked-secrets.destroy');
});
