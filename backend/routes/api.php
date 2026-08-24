<?php

use App\Domains\Matter\Enums\MatterDomain;
use App\Http\Controllers\Api\V1\ArchivedProjectController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanyIdentityController;
use App\Http\Controllers\Api\V1\CompanyManagementController;
use App\Http\Controllers\Api\V1\CompanyShareholderController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentRelationController;
use App\Http\Controllers\Api\V1\IndividualCompanyController;
use App\Http\Controllers\Api\V1\IndividualController;
use App\Http\Controllers\Api\V1\IndividualIdentityController;
use App\Http\Controllers\Api\V1\MatterAssignmentController;
use App\Http\Controllers\Api\V1\MatterController;
use App\Http\Controllers\Api\V1\MatterLifecycleController;
use App\Http\Controllers\Api\V1\MatterPartyController;
use App\Http\Controllers\Api\V1\MatterStageController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotaryDeedController;
use App\Http\Controllers\Api\V1\PartyDirectoryController;
use App\Http\Controllers\Api\V1\PartyDuplicateController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProjectAssignmentController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectPartyController;
use App\Http\Controllers\Api\V1\ProjectStatusController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\RolePermissionController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\TaskCommentController;
use App\Http\Controllers\Api\V1\TaskController;
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
        /*
         * The unified Party Directory (M2.5) — the first and only generic Party
         * endpoint, and deliberately **read-only**.
         *
         * No POST, PATCH, or DELETE here, ever. Individual and Company own their
         * lifecycles with their own permissions, validation, and aggregate
         * rules; a generic Party mutation route would be a second way to write
         * the same records with none of that (D-078).
         *
         * No new permission either: visibility is the union of `parties.view`
         * and `companies.view`, each evaluated at its own scope.
         */
        Route::get('parties', [PartyDirectoryController::class, 'index'])
            ->name('api.v1.parties.index');

        /*
         * Project — the M3 aggregate root (M3.3).
         *
         * `projects/archived` is registered **before** `projects/{project}` so
         * the literal path wins; otherwise the router would bind `archived` as an
         * id and answer 404 for a surface that exists.
         *
         * The archived surface answers to `projects.restore`, not
         * `projects.view` (D-093). Widening ordinary view to include
         * soft-deleted rows would expose archived work to everyone who can read
         * Projects at all — a much larger group than those who may restore.
         *
         * Assignment and status get their own paths because they are their own
         * capabilities: `projects.assign` writes `pic_user_id`,
         * `projects.change_status` writes `status`, and generic `PATCH` reaches
         * neither (D-091). A field governed by its own permission gets its own
         * endpoint, and the generic update refuses it —
         * `06_API_CONVENTIONS.md` section 22.
         *
         * `DELETE` archives. Project records are never destroyed, and `restore`
         * is a POST to the archived record rather than an inverse `DELETE`.
         *
         * Participation is nested under the Project (M3.4) — see below. No Matter
         * route: that is M4.
         */
        Route::get('projects/archived', [ArchivedProjectController::class, 'index'])
            ->name('api.v1.projects.archived.index');
        Route::post('projects/{project}/restore', [ArchivedProjectController::class, 'restore'])
            ->whereUlid('project')->withTrashed()->name('api.v1.projects.restore');

        Route::get('projects/{project}/assignment/options', [ProjectAssignmentController::class, 'options'])
            ->whereUlid('project')->name('api.v1.projects.assignment.options');
        Route::patch('projects/{project}/assignment', [ProjectAssignmentController::class, 'update'])
            ->whereUlid('project')->name('api.v1.projects.assignment.update');

        Route::patch('projects/{project}/status', [ProjectStatusController::class, 'update'])
            ->whereUlid('project')->name('api.v1.projects.status.update');

        /*
         * Project <-> Party participation (M3.4, D-098).
         *
         * Nested under the Project because that is what owns it: participation
         * authority is the parent Project's Data Scope, and there is deliberately
         * no top-level `/project-parties` collection to reach a row without
         * naming the Project it belongs to.
         *
         * Two capabilities: `projects.parties.view` reads the list,
         * `projects.parties.manage` writes it. Neither implies the other and
         * `projects.update` reaches neither.
         *
         * `party-options` is the candidate source. It is authorized by `manage`
         * **and** additionally applies Party-domain visibility per subtype, so
         * managing participation never becomes a way to discover Parties.
         *
         * `DELETE` here genuinely deletes — the relationship row only, never the
         * Project and never the Party. `project_parties` is current working
         * state rather than a ledger, which is the one place M3.4 deliberately
         * differs from `company_people` (D-083 vs D-098).
         */
        Route::get('projects/{project}/party-options', [ProjectPartyController::class, 'options'])
            ->whereUlid('project')->name('api.v1.projects.parties.options');

        Route::get('projects/{project}/parties', [ProjectPartyController::class, 'index'])
            ->whereUlid('project')->name('api.v1.projects.parties.index');
        Route::post('projects/{project}/parties', [ProjectPartyController::class, 'store'])
            ->whereUlid('project')->name('api.v1.projects.parties.store');
        Route::patch('projects/{project}/parties/{projectParty}', [ProjectPartyController::class, 'update'])
            ->whereUlid('project')->whereUlid('projectParty')->name('api.v1.projects.parties.update');
        Route::delete('projects/{project}/parties/{projectParty}', [ProjectPartyController::class, 'destroy'])
            ->whereUlid('project')->whereUlid('projectParty')->name('api.v1.projects.parties.destroy');

        /*
         * Matters (M4.4, D-109) — one controller set, two domain roots.
         *
         * `/notary/matters` and `/ppat/matters` are separate address spaces, and
         * the segment is not decoration: **the route decides the permission
         * namespace** (D-101), so `notary.matters.*` authorizes one root and
         * `ppat.matters.*` the other. The domain is never read from a request
         * body and never inferred from the record being addressed. It reaches the
         * controller as a route default, which is what makes it route context
         * rather than caller input.
         *
         * A Matter of the other domain answers **404**, not 403: the lookup is
         * constrained by domain, so a Notary address handed a PPAT id behaves as
         * though nothing is there. A 403 would confirm the record exists in a
         * domain the caller never named — the D-098 nested-binding convention
         * applied to a domain root.
         *
         * Assignment, completion, and cancellation get their own paths because
         * they are their own capabilities: `*.matters.assign` writes
         * `pic_user_id`, `*.matters.complete` and `*.matters.cancel` write
         * `status`, and generic `PATCH` reaches none of them (D-091,
         * `06_API_CONVENTIONS.md` section 22).
         *
         * **There is no `DELETE` and no stage route.** M4 ships no Matter archive
         * or restore lifecycle — `deleted_at` is reserved schema capability with
         * no code path (D-102) — and `*.matters.change_stage` has no workflow to
         * move until M4.7 (D-104), so it stays unreachable and is badged deferred
         * rather than given a route that pretends.
         *
         * `service-type-options` is registered **before** the `{matter}` binding;
         * reversed, the literal path would be read as a Matter id and answer 404.
         *
         * **Participation (M4.5, D-105)** is nested under the Matter because that
         * is what owns it: participation authority is the parent Matter's Data
         * Scope, and there is deliberately no top-level `/matter-parties`
         * collection to reach a row without naming the Matter it belongs to.
         * `*.matters.parties.view` reads the list, `*.matters.parties.manage`
         * writes it, neither implies the other, and `*.matters.update` reaches
         * neither. `party-options` is the candidate source, authorized by
         * `manage` **and** additionally applying Party-domain visibility per
         * subtype, so managing participation never becomes a way to discover
         * Parties. `DELETE` here genuinely deletes — the relationship row only,
         * never the Matter and never the Party.
         */
        foreach ([
            'notary' => MatterDomain::NOTARY,
            'ppat' => MatterDomain::PPAT,
        ] as $segment => $matterDomain) {
            // `defaults()` is a per-route method rather than a group one, so the
            // domain is stamped on each route explicitly. Verbose, and the
            // verbosity is the safe kind: every route says which capability
            // namespace it authorizes through.
            $domainValue = $matterDomain->value;

            Route::prefix($segment)
                ->name("api.v1.{$segment}.matters.")
                ->group(function () use ($domainValue): void {
                    Route::get('matters/service-type-options', [MatterController::class, 'serviceTypeOptions'])
                        ->defaults('domain', $domainValue)->name('service-type-options');

                    Route::get('matters/{matter}/assignment/options', [MatterAssignmentController::class, 'options'])
                        ->whereUlid('matter')->defaults('domain', $domainValue)->name('assignment.options');
                    Route::patch('matters/{matter}/assignment', [MatterAssignmentController::class, 'update'])
                        ->whereUlid('matter')->defaults('domain', $domainValue)->name('assignment.update');

                    Route::post('matters/{matter}/complete', [MatterLifecycleController::class, 'complete'])
                        ->whereUlid('matter')->defaults('domain', $domainValue)->name('complete');
                    Route::post('matters/{matter}/cancel', [MatterLifecycleController::class, 'cancel'])
                        ->whereUlid('matter')->defaults('domain', $domainValue)->name('cancel');

                    /*
                     * The running workflow (M4.7, D-112). Reading answers to the
                     * Matter's own `view` capability — a stage is part of what a
                     * Matter is, not a separate resource with its own audience —
                     * while `options` and `move` answer to
                     * `*.matters.change_stage`, canonical since the catalogue was
                     * transcribed and badged deferred until this milestone gave
                     * it a route.
                     *
                     * There is **no transition matrix** (D-104): `move` checks
                     * that the target stage belongs to this Matter's workflow and
                     * is open, never which stage may follow which.
                     */
                    Route::get('matters/{matter}/stages', [MatterStageController::class, 'index'])
                        ->whereUlid('matter')->defaults('domain', $domainValue)->name('stages.index');
                    Route::get('matters/{matter}/stages/options', [MatterStageController::class, 'options'])
                        ->whereUlid('matter')->defaults('domain', $domainValue)->name('stages.options');
                    Route::post('matters/{matter}/stages/move', [MatterStageController::class, 'move'])
                        ->whereUlid('matter')->defaults('domain', $domainValue)->name('stages.move');

                    Route::get('matters/{matter}/party-options', [MatterPartyController::class, 'options'])
                        ->whereUlid('matter')->defaults('domain', $domainValue)->name('parties.options');

                    Route::get('matters/{matter}/parties', [MatterPartyController::class, 'index'])
                        ->whereUlid('matter')->defaults('domain', $domainValue)->name('parties.index');
                    Route::post('matters/{matter}/parties', [MatterPartyController::class, 'store'])
                        ->whereUlid('matter')->defaults('domain', $domainValue)->name('parties.store');
                    Route::patch('matters/{matter}/parties/{matterParty}', [MatterPartyController::class, 'update'])
                        ->whereUlid('matter')->whereUlid('matterParty')
                        ->defaults('domain', $domainValue)->name('parties.update');
                    Route::delete('matters/{matter}/parties/{matterParty}', [MatterPartyController::class, 'destroy'])
                        ->whereUlid('matter')->whereUlid('matterParty')
                        ->defaults('domain', $domainValue)->name('parties.destroy');

                    Route::get('matters', [MatterController::class, 'index'])
                        ->defaults('domain', $domainValue)->name('index');
                    Route::post('matters', [MatterController::class, 'store'])
                        ->defaults('domain', $domainValue)->name('store');
                    Route::get('matters/{matter}', [MatterController::class, 'show'])
                        ->whereUlid('matter')->defaults('domain', $domainValue)->name('show');
                    Route::patch('matters/{matter}', [MatterController::class, 'update'])
                        ->whereUlid('matter')->defaults('domain', $domainValue)->name('update');
                });
        }

        /*
         * Document Management (M5.2, D-117).
         *
         * **One surface, not two.** Unlike Matter there is no `/notary/documents`
         * and no `/ppat/documents`: `documents.*` is a single canonical namespace
         * with no domain split, so there is nothing for a route prefix to select.
         * D-101 governs Matter because *that* catalogue is split; it has no
         * bearing here.
         *
         * **`options` is declared before `{document}`**, or the literal segment
         * would bind as a document id and answer 404 — and `whereUlid` alone would
         * not save it, since the failure would be a silent miss rather than an
         * error. Every id parameter is `whereUlid` constrained so a malformed id
         * never reaches a query.
         *
         * Six separate acts, six separate capabilities, and none implies another:
         * `documents.upload`, `download`, `update`, `verify`, `archive`, `delete`.
         * The D-091 discipline.
         *
         * **`download` streams from a surface that authorized the actor first**
         * (D-114). There is no signed URL, no temporary URL, and no route into the
         * private disk — M5.0 removed the two that existed.
         */
        Route::get('documents/options', [DocumentController::class, 'options'])
            ->name('api.v1.documents.options');

        Route::get('documents', [DocumentController::class, 'index'])->name('api.v1.documents.index');
        Route::post('documents', [DocumentController::class, 'store'])->name('api.v1.documents.store');

        Route::get('documents/{document}', [DocumentController::class, 'show'])
            ->whereUlid('document')->name('api.v1.documents.show');
        Route::patch('documents/{document}', [DocumentController::class, 'update'])
            ->whereUlid('document')->name('api.v1.documents.update');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])
            ->whereUlid('document')->name('api.v1.documents.destroy');

        /*
         * Document relations (M5.3, D-118).
         *
         * Attaching answers to two capabilities at once: `documents.update` on the
         * Document, and the target record's own view capability resolved through
         * its domain's visibility class. `documents.update` is authority over a
         * document's filing; it is never authority to discover which records
         * exist.
         *
         * **No new permission is registered.** Attaching is a correction to a
         * document's own filing rather than a new act, so the canonical catalogue
         * is unchanged at 177.
         *
         * **Three of seven types.** `property`, `notary_deed` and `ppat_deed`
         * are recommended by the ERD and their tables do not exist (D-115), so
         * they are refused by the enum with a field error rather than stubbed.
         *
         * There is deliberately **no `GET /{entity}/{id}/documents`** — that
         * question is already answered by `GET /documents?project_id=…`, and a
         * second address for one question is two surfaces to keep in step.
         */
        Route::get('documents/{document}/relations', [DocumentRelationController::class, 'index'])
            ->whereUlid('document')->name('api.v1.documents.relations.index');
        Route::post('documents/{document}/relations', [DocumentRelationController::class, 'store'])
            ->whereUlid('document')->name('api.v1.documents.relations.store');
        Route::delete('documents/{document}/relations', [DocumentRelationController::class, 'destroy'])
            ->whereUlid('document')->name('api.v1.documents.relations.destroy');

        Route::get('documents/{document}/download', [DocumentController::class, 'download'])
            ->whereUlid('document')->name('api.v1.documents.download');
        Route::post('documents/{document}/verify', [DocumentController::class, 'verify'])
            ->whereUlid('document')->name('api.v1.documents.verify');
        Route::post('documents/{document}/archive', [DocumentController::class, 'archive'])
            ->whereUlid('document')->name('api.v1.documents.archive');

        /*
         * Tasks (M5.4, D-119).
         *
         * **One surface, not two.** `tasks.*` is a single canonical namespace with
         * no Notary/PPAT split, so there is nothing for a route prefix to select.
         *
         * **Seven acts, seven capabilities, and none implies another** —
         * `tasks.create`, `update`, `assign`, `complete`, `reopen`, `delete`, and
         * `view` for reading and commenting. `tasks.reopen` is its own code rather
         * than part of completion, which is what the registry says and what an
         * office would want: closing work and un-closing it are different trusts.
         *
         * `cancel` and `destroy` share `tasks.delete`: cancelling is what makes
         * deletion available, since nothing still live may be removed. There is no
         * `tasks.cancel` in the catalogue and this milestone invents none.
         *
         * **`options` is declared before `{task}`**, or the literal segment would
         * bind as a task id and answer 404.
         *
         * There is deliberately **no `POST /tasks/{task}/status`**: the three live
         * statuses are ordinary editing and belong to `PATCH`, while the two
         * settled ones answer to their own capabilities above.
         */
        Route::get('tasks/options', [TaskController::class, 'options'])->name('api.v1.tasks.options');

        Route::get('tasks', [TaskController::class, 'index'])->name('api.v1.tasks.index');
        Route::post('tasks', [TaskController::class, 'store'])->name('api.v1.tasks.store');

        Route::get('tasks/{task}', [TaskController::class, 'show'])
            ->whereUlid('task')->name('api.v1.tasks.show');
        Route::patch('tasks/{task}', [TaskController::class, 'update'])
            ->whereUlid('task')->name('api.v1.tasks.update');
        Route::delete('tasks/{task}', [TaskController::class, 'destroy'])
            ->whereUlid('task')->name('api.v1.tasks.destroy');

        Route::patch('tasks/{task}/assignment', [TaskController::class, 'assign'])
            ->whereUlid('task')->name('api.v1.tasks.assign');
        Route::post('tasks/{task}/complete', [TaskController::class, 'complete'])
            ->whereUlid('task')->name('api.v1.tasks.complete');
        Route::post('tasks/{task}/reopen', [TaskController::class, 'reopen'])
            ->whereUlid('task')->name('api.v1.tasks.reopen');
        Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel'])
            ->whereUlid('task')->name('api.v1.tasks.cancel');

        Route::get('tasks/{task}/comments', [TaskCommentController::class, 'index'])
            ->whereUlid('task')->name('api.v1.tasks.comments.index');
        Route::post('tasks/{task}/comments', [TaskCommentController::class, 'store'])
            ->whereUlid('task')->name('api.v1.tasks.comments.store');

        /*
         * Notarial Deeds (M6.2, D-120).
         *
         * **One root, not two.** `notary.deeds.*` is a Notary-only namespace — PPAT
         * deeds are a different table in a different milestone — so unlike Matter
         * there is no `foreach` over two domains here. The `notary` prefix is still
         * what selects the permission namespace (D-101); there is simply one case.
         *
         * **Seven acts, seven capabilities, none implying another** —
         * `notary.deeds.view`, `create`, `update`, `review`, `approve`, `finalize`
         * and `number`. An office that separates preparing a deed from approving it
         * is expressing something about who may bind it legally, so `update` reaching
         * `review`, or `finalize` reaching `number`, would collapse a distinction the
         * catalogue drew deliberately.
         *
         * **`deeds/{deed}/number` is its own route**, not folded into `finalize`.
         * `notary.deeds.number` has been canonical since M1.2 and nothing had used
         * it. Numbering at finalization would assert *when* a deed is numbered, which
         * is half of `08_NOTARY_WORKFLOW.md` section 6's first open question.
         *
         * **`options` is declared before `{deed}`**, or the literal segment would
         * bind as a deed id and answer 404.
         *
         * **There is deliberately no `DELETE` and no `void` route.** `notary_deeds`
         * has no `deleted_at`, the catalogue has no `notary.deeds.delete`,
         * `notary.deeds.void` or `notary.deeds.lock`, and the correction mechanisms
         * that would need them are an open domain question (D-120). A route that
         * pretended otherwise would be a control nobody can authorize.
         */
        Route::prefix('notary')->name('api.v1.notary.deeds.')->group(function (): void {
            Route::get('deeds/options', [NotaryDeedController::class, 'options'])->name('options');

            Route::get('deeds', [NotaryDeedController::class, 'index'])->name('index');
            Route::post('deeds', [NotaryDeedController::class, 'store'])->name('store');

            Route::get('deeds/{deed}', [NotaryDeedController::class, 'show'])
                ->whereUlid('deed')->name('show');
            Route::patch('deeds/{deed}', [NotaryDeedController::class, 'update'])
                ->whereUlid('deed')->name('update');

            Route::patch('deeds/{deed}/review', [NotaryDeedController::class, 'review'])
                ->whereUlid('deed')->name('review');
            Route::patch('deeds/{deed}/approve', [NotaryDeedController::class, 'approve'])
                ->whereUlid('deed')->name('approve');
            Route::patch('deeds/{deed}/finalize', [NotaryDeedController::class, 'finalize'])
                ->whereUlid('deed')->name('finalize');
            Route::patch('deeds/{deed}/number', [NotaryDeedController::class, 'recordNumber'])
                ->whereUlid('deed')->name('number');
        });

        Route::get('projects', [ProjectController::class, 'index'])->name('api.v1.projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('api.v1.projects.store');
        Route::get('projects/{project}', [ProjectController::class, 'show'])
            ->whereUlid('project')->name('api.v1.projects.show');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])
            ->whereUlid('project')->name('api.v1.projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'archive'])
            ->whereUlid('project')->name('api.v1.projects.archive');

        /*
         * Advisory duplicate candidates (M2.5, D-084, D-086).
         *
         * POST even though nothing mutates: the request body carries the
         * identifiers being typed, and a GET would put a NIK in a URL, a history
         * entry, a proxy log, and a cached response — the reasoning that made
         * identity reveal a POST (D-082).
         *
         * `duplicate-candidates` precedes `{individual}` so the literal path
         * wins. Its own named limiter, kept clear of both the `security.*`
         * buckets and the identity reveal bucket: these are identity *existence*
         * questions, a different operation from disclosing a stored value, and
         * exhausting one must not disable the other (the M1.9 defect, D-071).
         */
        Route::post('individuals/duplicate-candidates', [PartyDuplicateController::class, 'individualsForCreate'])
            ->middleware('throttle:party.duplicate.check')
            ->name('api.v1.individuals.duplicate-candidates.create');
        Route::post('individuals/{individual}/duplicate-candidates', [PartyDuplicateController::class, 'individualsForUpdate'])
            ->whereUlid('individual')->middleware('throttle:party.duplicate.check')
            ->name('api.v1.individuals.duplicate-candidates.update');

        Route::post('companies/duplicate-candidates', [PartyDuplicateController::class, 'companiesForCreate'])
            ->middleware('throttle:party.duplicate.check')
            ->name('api.v1.companies.duplicate-candidates.create');
        Route::post('companies/{company}/duplicate-candidates', [PartyDuplicateController::class, 'companiesForUpdate'])
            ->whereUlid('company')->middleware('throttle:party.duplicate.check')
            ->name('api.v1.companies.duplicate-candidates.update');

        /*
         * The companies a person is involved with — M2.4's relationship surfaces
         * read from the person's side (M2.5).
         *
         * Read-only. Relationship management stays on the Company, where
         * D-085's add-and-close model lives. Two endpoints rather than one, so
         * the management/ownership permission split survives the reversal.
         */
        Route::get('individuals/{individual}/companies/management', [IndividualCompanyController::class, 'management'])
            ->whereUlid('individual')->name('api.v1.individuals.companies.management');
        Route::get('individuals/{individual}/companies/shareholders', [IndividualCompanyController::class, 'shareholders'])
            ->whereUlid('individual')->name('api.v1.individuals.companies.shareholders');

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

        /*
         * Company relationships — who runs the organization, and who owns it
         * (M2.4).
         *
         * Two families rather than one, because they answer to two permissions
         * and always have: `companies.management.*` and
         * `companies.shareholders.*` are independent capabilities, and neither
         * implies the other (D-083). Each route points at a controller whose
         * category is fixed by its class, so no request can choose which surface
         * it is talking to.
         *
         * **Add and end, and nothing else.** No `DELETE` and no generic `PATCH`:
         * `company_people` is history, and "who was the director in March" must
         * stay answerable because deeds executed in March depend on it.
         * Superseding a relationship is end-then-add — two rows, both readable.
         *
         * `options` precedes `{relationship}` so the literal path wins. Both are
         * guarded by the category's *update* permission: picking a person is
         * part of recording a relationship, and requiring `parties.view` for it
         * would grant the whole Party directory for a narrower task.
         */
        Route::get('companies/{company}/management', [CompanyManagementController::class, 'index'])
            ->whereUlid('company')->name('api.v1.companies.management.index');
        Route::post('companies/{company}/management', [CompanyManagementController::class, 'store'])
            ->whereUlid('company')->name('api.v1.companies.management.store');
        Route::get('companies/{company}/management/options', [CompanyManagementController::class, 'options'])
            ->whereUlid('company')->name('api.v1.companies.management.options');
        Route::post('companies/{company}/management/{relationship}/end', [CompanyManagementController::class, 'end'])
            ->whereUlid('company')->whereUlid('relationship')
            ->name('api.v1.companies.management.end');

        Route::get('companies/{company}/shareholders', [CompanyShareholderController::class, 'index'])
            ->whereUlid('company')->name('api.v1.companies.shareholders.index');
        Route::post('companies/{company}/shareholders', [CompanyShareholderController::class, 'store'])
            ->whereUlid('company')->name('api.v1.companies.shareholders.store');
        Route::get('companies/{company}/shareholders/options', [CompanyShareholderController::class, 'options'])
            ->whereUlid('company')->name('api.v1.companies.shareholders.options');
        Route::post('companies/{company}/shareholders/{relationship}/end', [CompanyShareholderController::class, 'end'])
            ->whereUlid('company')->whereUlid('relationship')
            ->name('api.v1.companies.shareholders.end');

        Route::apiResource('companies', CompanyController::class)
            ->except('destroy')
            ->whereUlid('company');
    });
});
