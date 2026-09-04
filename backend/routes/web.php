<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

/*
 * Session authentication endpoints.
 *
 * These live on the web routes so they carry the session and CSRF middleware.
 * `GET /sanctum/csrf-cookie` is registered by Sanctum itself.
 */
Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

/*
 * The second step of a two-factor login.
 *
 * Unauthenticated in the middleware sense — no session has been created yet —
 * but reachable only with a live challenge in the session, which exists only
 * after a correct password. It accepts no email and no user id, so it cannot be
 * called as an alternative way in (D-075).
 *
 * Its own rate limit lives in the controller, keyed on the pending account
 * rather than on a submitted address.
 */
Route::post('login/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
    ->name('login.two-factor');

/*
 * Completing a password reset from an emailed link.
 *
 * Unauthenticated by necessity, so the throttle is the only thing standing
 * between this route and unlimited token guesses. It creates no session on
 * success — the user signs in again, which is what keeps an account's second
 * factor from being bypassed by a single email (D-072).
 */
Route::post('password-reset', [PasswordResetController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('password.reset');
