<?php

use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\RolePermissionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserRoleController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');

    // Sanctum resolves this from the session cookie for stateful first-party
    // requests. No bearer token is involved.
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', MeController::class)->name('api.v1.me');

        // The authenticated user's own account. No permission guards these and
        // no id is accepted — the target is always the caller (D-066).
        // Administrative access to somebody else's record is `users.*`.
        Route::get('profile', [ProfileController::class, 'show'])->name('api.v1.profile.show');
        Route::patch('profile', [ProfileController::class, 'update'])->name('api.v1.profile.update');

        // Role definitions. `whereNumber` because roles keep the package's
        // integer key: without it a non-numeric id would reach PostgreSQL as
        // an invalid integer and surface as a 500 rather than a 404.
        //
        // No nested permission, scope, or member routes — those are separate
        // capabilities owned by later milestones.
        // Authorization configuration. Guarded by permissions.view /
        // permissions.assign at the ALL scope, never by roles.update or
        // users.update — changing what a role or a person can do is permission
        // administration wherever it appears.
        Route::get('permissions', [PermissionController::class, 'index'])->name('api.v1.permissions.index');

        Route::get('roles/{role}/permissions', [RolePermissionController::class, 'index'])
            ->whereNumber('role')->name('api.v1.roles.permissions.index');
        Route::put('roles/{role}/permissions', [RolePermissionController::class, 'update'])
            ->whereNumber('role')->name('api.v1.roles.permissions.update');

        Route::apiResource('roles', RoleController::class)->whereNumber('role');

        // User accounts. `options` is declared before the resource routes so
        // the literal path is matched ahead of `users/{user}`.
        //
        // No DELETE: the permission registry defines no `users.delete`, and
        // accounts are retired with the explicit activation actions below
        // rather than removed. No `users/{user}/roles` either — assignment is a
        // separate capability owned by a later milestone.
        Route::get('users/options', [UserController::class, 'options'])->name('api.v1.users.options');

        Route::get('users/{user}/roles', [UserRoleController::class, 'index'])
            ->whereUlid('user')->name('api.v1.users.roles.index');
        Route::put('users/{user}/roles', [UserRoleController::class, 'update'])
            ->whereUlid('user')->name('api.v1.users.roles.update');

        Route::post('users/{user}/disable', [UserController::class, 'disable'])
            ->whereUlid('user')->name('api.v1.users.disable');
        Route::post('users/{user}/enable', [UserController::class, 'enable'])
            ->whereUlid('user')->name('api.v1.users.enable');

        Route::apiResource('users', UserController::class)
            ->except('destroy')
            ->whereUlid('user');
    });
});
