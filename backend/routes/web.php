<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Session authentication endpoints.
 *
 * These live on the web routes so they carry the session and CSRF middleware.
 * `GET /sanctum/csrf-cookie` is registered by Sanctum itself.
 */
Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
