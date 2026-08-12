<?php

use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanyIdentityController;
use App\Http\Controllers\Api\V1\IndividualController;
use App\Http\Controllers\Api\V1\IndividualIdentityController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\RolePermissionController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserRoleController;
use App\Http\Controllers\Api\V1\UserSecurityController;
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

        /*
         * The caller's own account security. Same boundary as `profile`: no
         * permission, no id parameter, target is always the caller. Requiring a
         * `security.*` permission here would let a user be forbidden from
         * changing their own password, which is not a policy anybody wants.
         *
         * Two named rate limiters, defined in AppServiceProvider. Every route
         * taking `current_password` shares `security.password` deliberately, so
         * the rule cannot be used as an oracle by rotating between endpoints;
         * the two-factor setup routes take no password and are limited
         * separately.
         */
        Route::get('security', [SecurityController::class, 'show'])->name('api.v1.security.show');

        Route::put('security/password', [SecurityController::class, 'updatePassword'])
            ->middleware('throttle:security.password')->name('api.v1.security.password');

        Route::post('security/email', [SecurityController::class, 'requestEmailChange'])
            ->middleware('throttle:security.password')->name('api.v1.security.email.request');
        Route::post('security/email/verify', [SecurityController::class, 'verifyEmailChange'])
            ->middleware('throttle:security.two-factor')->name('api.v1.security.email.verify');
        Route::delete('security/email', [SecurityController::class, 'cancelEmailChange'])
            ->name('api.v1.security.email.cancel');

        // Two-factor enrolment. `store` issues a secret and changes nothing
        // about login; `confirm` is where it takes effect. Turning it off and
        // replacing recovery codes both re-prove the password, so those two sit
        // in the password bucket rather than the setup one.
        Route::post('security/two-factor', [TwoFactorController::class, 'store'])
            ->middleware('throttle:security.two-factor')->name('api.v1.security.two-factor.store');
        Route::post('security/two-factor/confirm', [TwoFactorController::class, 'confirm'])
            ->middleware('throttle:security.two-factor')->name('api.v1.security.two-factor.confirm');
        Route::delete('security/two-factor', [TwoFactorController::class, 'destroy'])
            ->middleware('throttle:security.password')->name('api.v1.security.two-factor.destroy');
        Route::post('security/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
            ->middleware('throttle:security.password')->name('api.v1.security.two-factor.recovery-codes');

        // The caller's own signed-in devices. `sessions/others` precedes
        // `sessions/{session}` so the literal path wins.
        Route::get('security/sessions', [SessionController::class, 'index'])
            ->name('api.v1.security.sessions.index');
        Route::delete('security/sessions/others', [SessionController::class, 'destroyOthers'])
            ->middleware('throttle:security.password')->name('api.v1.security.sessions.others');
        Route::delete('security/sessions/{session}', [SessionController::class, 'destroy'])
            ->name('api.v1.security.sessions.destroy');

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

        /*
         * Administering another user's account security.
         *
         * Each behind its own canonical permission: `users.reset_password`,
         * `security.sessions.view`, `security.sessions.revoke`, and
         * `security.mfa.manage`. Nothing here returns a password, a reset token,
         * a two-factor secret, a recovery code, or a raw session id — an
         * administrator restores or removes access, never acquires it (D-071).
         */
        Route::post('users/{user}/password-reset', [UserSecurityController::class, 'sendPasswordReset'])
            ->whereUlid('user')->middleware('throttle:security.admin')
            ->name('api.v1.users.password-reset');

        Route::get('users/{user}/sessions', [UserSecurityController::class, 'sessions'])
            ->whereUlid('user')->name('api.v1.users.sessions.index');
        Route::delete('users/{user}/sessions', [UserSecurityController::class, 'revokeSessions'])
            ->whereUlid('user')->name('api.v1.users.sessions.destroy');

        Route::delete('users/{user}/two-factor', [UserSecurityController::class, 'disableTwoFactor'])
            ->whereUlid('user')->name('api.v1.users.two-factor.destroy');

        Route::apiResource('users', UserController::class)
            ->except('destroy')
            ->whereUlid('user');

        /*
         * Individuals — the first Party-domain business surface (M2.2).
         *
         * `options` precedes `{individual}` so the literal path wins. Every route
         * is bound to the Party ULID and resolves the Individual subtype only, so
         * a Company id answers 404 rather than leaking that it exists.
         *
         * No DELETE and no restore: Party records are archived, never destroyed,
         * and no restore permission exists to authorize one (D-081).
         *
         * The identity surface sits under its own path because it answers to its
         * own permissions. Reveal is POST rather than GET so a raw identifier
         * cannot land in a cached response, a browser history entry, or a URL —
         * and carries its own named limiter, kept clear of the `security.*`
         * buckets so neither can exhaust the other (D-082).
         */
        Route::get('individuals/options', [IndividualController::class, 'options'])
            ->name('api.v1.individuals.options');

        Route::post('individuals/{individual}/archive', [IndividualController::class, 'archive'])
            ->whereUlid('individual')->name('api.v1.individuals.archive');

        Route::get('individuals/{individual}/identity', [IndividualIdentityController::class, 'show'])
            ->whereUlid('individual')->name('api.v1.individuals.identity.show');
        Route::patch('individuals/{individual}/identity', [IndividualIdentityController::class, 'update'])
            ->whereUlid('individual')->name('api.v1.individuals.identity.update');

        Route::post('individuals/{individual}/identity/nik/reveal', [IndividualIdentityController::class, 'revealNik'])
            ->whereUlid('individual')->middleware('throttle:party.identity.reveal')
            ->name('api.v1.individuals.identity.nik.reveal');
        Route::post('individuals/{individual}/identity/npwp/reveal', [IndividualIdentityController::class, 'revealNpwp'])
            ->whereUlid('individual')->middleware('throttle:party.identity.reveal')
            ->name('api.v1.individuals.identity.npwp.reveal');

        Route::apiResource('individuals', IndividualController::class)
            ->except('destroy')
            ->whereUlid('individual');

        /*
         * Companies — the second Party-domain business surface (M2.3).
         *
         * Structurally the mirror of the Individual family above, and
         * deliberately so: one Party aggregate, two subtypes, one shape.
         *
         * Lifecycle authorizes on `companies.*`. Sensitive identity authorizes on
         * `parties.identity.*`, because the identity surface belongs to the
         * aggregate — the Company tax identifier is the NPWP and reveals through
         * the same canonical `parties.identity.npwp.view_full` an Individual's
         * does. No `companies.identity.*` family exists (D-082).
         *
         * Reveal shares the `party.identity.reveal` limiter with the Individual
         * reveals on purpose: it is one per-actor budget for raw Party identity
         * disclosure, so working through a directory cannot buy extra attempts by
         * alternating between subtypes. It remains clear of the `security.*`
         * buckets (D-071).
         *
         * No route here touches `company_people`. Directors, commissioners, and
         * shareholders answer to `companies.management.*` and
         * `companies.shareholders.*`, which M2.4 owns and this milestone does not
         * implement (D-083).
         */
        Route::get('companies/options', [CompanyController::class, 'options'])
            ->name('api.v1.companies.options');

        Route::post('companies/{company}/archive', [CompanyController::class, 'archive'])
            ->whereUlid('company')->name('api.v1.companies.archive');

        Route::get('companies/{company}/identity', [CompanyIdentityController::class, 'show'])
            ->whereUlid('company')->name('api.v1.companies.identity.show');
        Route::patch('companies/{company}/identity', [CompanyIdentityController::class, 'update'])
            ->whereUlid('company')->name('api.v1.companies.identity.update');

        Route::post('companies/{company}/identity/tax-id/reveal', [CompanyIdentityController::class, 'revealTaxId'])
            ->whereUlid('company')->middleware('throttle:party.identity.reveal')
            ->name('api.v1.companies.identity.tax-id.reveal');

        Route::apiResource('companies', CompanyController::class)
            ->except('destroy')
            ->whereUlid('company');
    });
});
