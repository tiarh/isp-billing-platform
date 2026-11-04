<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\TotpController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login-global');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email')
        ->middleware('throttle:6,1');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.store');

    Route::get('security/totp/challenge', [TotpController::class, 'challengePage'])
        ->name('totp.challenge');
    Route::post('security/totp/challenge', [TotpController::class, 'challenge'])
        ->middleware('throttle:10,1')
        ->name('totp.challenge.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    Route::middleware('superadmin')->prefix('security/totp')->name('totp.')->group(function () {
        Route::get('/', [TotpController::class, 'enrollment'])->name('enroll');
        Route::post('/enroll/begin', [TotpController::class, 'beginEnrollment'])->name('enroll.begin');
        Route::post('/enroll/confirm', [TotpController::class, 'confirmEnrollment'])->name('enroll.confirm');
        Route::get('/step-up', [TotpController::class, 'stepUpPage'])->name('step-up');
        Route::post('/step-up', [TotpController::class, 'stepUp'])
            ->middleware('throttle:10,1')
            ->name('step-up.store');
    });
});
