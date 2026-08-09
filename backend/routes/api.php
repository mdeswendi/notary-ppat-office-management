<?php

use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');

    // Sanctum resolves this from the session cookie for stateful first-party
    // requests. No bearer token is involved.
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', MeController::class)->name('api.v1.me');

        // Role definitions. `whereNumber` because roles keep the package's
        // integer key: without it a non-numeric id would reach PostgreSQL as
        // an invalid integer and surface as a 500 rather than a 404.
        //
        // No nested permission, scope, or member routes — those are separate
        // capabilities owned by later milestones.
        Route::apiResource('roles', RoleController::class)->whereNumber('role');
    });
});
