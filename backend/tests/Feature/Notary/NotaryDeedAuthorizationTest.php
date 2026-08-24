<?php

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Notary\NotaryDeedVisibility;
use App\Models\Matter;
use App\Models\NotaryDeed;
use App\Models\Office;
use App\Models\Project;
use App\Models\User;
use App\Policies\MatterPolicy;
use App\Policies\NotaryDeedPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An actor in a fresh Office holding the named permissions at one scope.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function deedActor(array $permissions = [], DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/**
 * A NOTARY Matter inside a given Office, optionally raised by or led by somebody.
 */
function deedMatter(Office $office, ?User $createdBy = null, ?User $pic = null): Matter
{
    $project = Project::factory()->for($office)->create();

    return Matter::factory()->for($project)->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
        'created_by' => $createdBy?->getKey(),
        'pic_user_id' => $pic?->getKey(),
    ]);
}

function deedPolicy(): NotaryDeedPolicy
{
    return app(NotaryDeedPolicy::class);
}

/*
|--------------------------------------------------------------------------
| A Deed's reach is its Matter's reach
|--------------------------------------------------------------------------
*/

it('reaches deeds on matters the actor raised, at OWN', function (): void {
    // The ruling M6.0 owed. A deed carries no `created_by` of its own, so OWN
    // resolves through the parent Matter (D-120).
    [$actor, $office] = deedActor(['notary.deeds.view'], DataScope::OWN);

    $colleague = User::factory()->for($office)->create();

    $mine = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $actor))->create();
    $theirs = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $colleague))->create();

    expect(deedPolicy()->view($actor, $mine))->toBeTrue()
        ->and(deedPolicy()->view($actor, $theirs))->toBeFalse();
});

it('reaches deeds on matters the actor leads, at ASSIGNED', function (): void {
    [$actor, $office] = deedActor(['notary.deeds.view'], DataScope::ASSIGNED);

    $colleague = User::factory()->for($office)->create();

    $ledByMe = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $colleague, pic: $actor))->create();
    $ledByThem = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $colleague, pic: $colleague))->create();

    expect(deedPolicy()->view($actor, $ledByMe))->toBeTrue()
        ->and(deedPolicy()->view($actor, $ledByThem))->toBeFalse();
});

it('unions OWN and ASSIGNED rather than ranking them', function (): void {
    // D-028. Two grants, two predicates, and the result is both sets — not the
    // "wider" of the two, because there is no such thing.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    $colleague = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'notary.deeds.view', DataScope::OWN);
    grantPermissionScope($actor, 'notary.deeds.view', DataScope::ASSIGNED);
    $actor = $actor->fresh();

    $raised = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $actor, pic: $colleague))->create();
    $led = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $colleague, pic: $actor))->create();
    $neither = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $colleague, pic: $colleague))->create();

    expect(deedPolicy()->view($actor, $raised))->toBeTrue()
        ->and(deedPolicy()->view($actor, $led))->toBeTrue()
        ->and(deedPolicy()->view($actor, $neither))->toBeFalse();
});

it('correlates the parent predicate to the deed own matter', function (): void {
    // The mistake the EXISTS subquery exists to avoid: an uncorrelated predicate
    // would turn OWN into "the actor has raised at least one Matter somewhere",
    // which would reach every deed in the deployment.
    [$actor, $office] = deedActor(['notary.deeds.view'], DataScope::OWN);

    $colleague = User::factory()->for($office)->create();

    // The actor has raised a Matter — just not this deed's.
    deedMatter($office, createdBy: $actor);

    $unrelated = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $colleague))->create();

    expect(deedPolicy()->view($actor, $unrelated))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The Matter supplies the predicate, never the grant
|--------------------------------------------------------------------------
*/

it('reaches no deed from a Matter capability alone', function (): void {
    // D-100 restated one level down, and the thing that makes resolving through
    // the parent safe: `notary.matters.view` at ALL still reaches nothing here.
    [$actor, $office] = deedActor(['notary.matters.view'], DataScope::ALL);

    $deed = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $actor, pic: $actor))->create();

    expect(deedPolicy()->view($actor, $deed))->toBeFalse()
        ->and(deedPolicy()->viewAny($actor))->toBeFalse();
});

it('confers no Matter authority from a Deed capability', function (): void {
    // The symmetric statement. Holding every deed code at ALL says nothing about
    // whether the actor may see, update or complete the parent Matter.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach (['notary.deeds.view', 'notary.deeds.update', 'notary.deeds.finalize'] as $code) {
        grantPermissionScope($actor, $code, DataScope::ALL);
    }
    $actor = $actor->fresh();

    $matter = deedMatter($office, createdBy: $actor);

    // `MatterPolicy::view` takes the domain as a third argument because the route
    // decides the namespace (D-101), not the record.
    expect(app(MatterPolicy::class)->view($actor, $matter, MatterDomain::NOTARY))
        ->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Office, and the scopes that reach nothing
|--------------------------------------------------------------------------
*/

it('reaches every deed in the actor office at OFFICE', function (): void {
    [$actor, $office] = deedActor(['notary.deeds.view'], DataScope::OFFICE);

    $colleague = User::factory()->for($office)->create();
    $mine = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $colleague, pic: $colleague))->create();

    $elsewhere = Office::factory()->create();
    $theirs = NotaryDeed::factory()->forMatter(deedMatter($elsewhere))->create();

    expect(deedPolicy()->view($actor, $mine))->toBeTrue()
        ->and(deedPolicy()->view($actor, $theirs))->toBeFalse();
});

it('crosses offices only at ALL', function (): void {
    [$actor] = deedActor(['notary.deeds.view'], DataScope::ALL);

    $elsewhere = NotaryDeed::factory()->create();

    expect(deedPolicy()->view($actor, $elsewhere))->toBeTrue();
});

it('reaches nothing on a TEAM-only grant', function (): void {
    // No Team entity exists (D-042), so the grant is refused outright rather than
    // serving a reliably empty page.
    [$actor, $office] = deedActor(['notary.deeds.view'], DataScope::TEAM);

    $deed = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $actor, pic: $actor))->create();

    expect(deedPolicy()->viewAny($actor))->toBeFalse()
        ->and(deedPolicy()->view($actor, $deed))->toBeFalse();
});

it('reaches nothing without the capability at all', function (): void {
    [$actor, $office] = deedActor();

    $deed = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $actor, pic: $actor))->create();

    expect(deedPolicy()->view($actor, $deed))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Creation asks two questions
|--------------------------------------------------------------------------
*/

it('refuses creation to an actor who cannot reach the matter', function (): void {
    // D-118's two-question rule: `notary.deeds.create` is authority to record a
    // deed, never authority to discover which Matters exist.
    [$actor, $office] = deedActor(['notary.deeds.create'], DataScope::OFFICE);

    $colleague = User::factory()->for($office)->create();
    $matter = deedMatter($office, createdBy: $colleague, pic: $colleague);

    // No `notary.matters.view` grant at all.
    expect(deedPolicy()->create($actor, $matter))->toBeFalse();

    grantPermissionScope($actor, 'notary.matters.view', DataScope::OFFICE);

    expect(deedPolicy()->create($actor->fresh(), $matter))->toBeTrue();
});

it('refuses creation against a PPAT matter', function (): void {
    // The route decides the namespace (D-101) and the record must agree with it.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'notary.deeds.create', DataScope::ALL);
    grantPermissionScope($actor, 'notary.matters.view', DataScope::ALL);
    $actor = $actor->fresh();

    $project = Project::factory()->for($office)->create();
    $ppat = Matter::factory()->for($project)->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::PPAT,
    ]);

    expect(deedPolicy()->create($actor, $ppat))->toBeFalse();
});

it('refuses creation in another office unless the actor holds ALL', function (): void {
    // A deed's Office is its Matter's, so an OFFICE-scoped actor may only record
    // deeds on Matters in their own Office.
    [$actor] = deedActor(['notary.deeds.create', 'notary.matters.view'], DataScope::OFFICE);

    $elsewhere = deedMatter(Office::factory()->create());

    expect(deedPolicy()->create($actor, $elsewhere))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Seven capabilities, and none implies another
|--------------------------------------------------------------------------
*/

it('grants exactly the one act the capability names', function (string $held, string $ability): void {
    // The D-091 discipline, load-bearing here rather than stylistic: an office that
    // separates preparing a deed from approving it is expressing something about
    // who may bind it legally.
    [$actor, $office] = deedActor([$held], DataScope::OFFICE);

    $deed = NotaryDeed::factory()->forMatter(deedMatter($office))->create();

    foreach (['view', 'update', 'review', 'approve', 'finalize', 'recordNumber'] as $candidate) {
        expect(deedPolicy()->{$candidate}($actor, $deed))->toBe($candidate === $ability);
    }
})->with([
    ['notary.deeds.view', 'view'],
    ['notary.deeds.update', 'update'],
    ['notary.deeds.review', 'review'],
    ['notary.deeds.approve', 'approve'],
    ['notary.deeds.finalize', 'finalize'],
    ['notary.deeds.number', 'recordNumber'],
]);

it('does not let finalize reach numbering', function (): void {
    // Folding numbering into finalization would assert that a deed is numbered when
    // it is finalized — half of "who assigns the number, and when?" (open question
    // one). `notary.deeds.number` is its own canonical capability.
    [$actor, $office] = deedActor(['notary.deeds.finalize'], DataScope::OFFICE);

    $deed = NotaryDeed::factory()->forMatter(deedMatter($office))->create();

    expect(deedPolicy()->finalize($actor, $deed))->toBeTrue()
        ->and(deedPolicy()->recordNumber($actor, $deed))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| A finalized deed is read-only
|--------------------------------------------------------------------------
*/

it('refuses update on a finalized deed even to a full capability holder', function (): void {
    // CLAUDE.md sections 29 and 64: once finalized, prevent normal edits. Checked in
    // the Policy so no interface offers an edit control that cannot work.
    [$actor, $office] = deedActor(['notary.deeds.update'], DataScope::ALL);

    $matter = deedMatter($office);
    $draft = NotaryDeed::factory()->forMatter($matter)->create();
    $finalized = NotaryDeed::factory()->forMatter($matter)->finalized()->create();

    expect(deedPolicy()->update($actor, $draft))->toBeTrue()
        ->and(deedPolicy()->update($actor, $finalized))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| No role names, no bypass, no invented abilities
|--------------------------------------------------------------------------
*/

it('gives a SUPER_ADMIN role name no bypass', function (): void {
    // The M6 brief specified approval and finalization as "default hanya PRINCIPAL
    // dan SUPER_ADMIN". Which roles hold a capability is office configuration; a
    // role-name check is the mechanism D-032, D-041 and D-048 forbid outright.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    $role = Role::findOrCreate('SUPER_ADMIN', 'web');
    $actor->assignRole($role);
    $actor = $actor->fresh();

    $deed = NotaryDeed::factory()->forMatter(deedMatter($office))->create();

    expect(deedPolicy()->approve($actor, $deed))->toBeFalse()
        ->and(deedPolicy()->finalize($actor, $deed))->toBeFalse();
});

it('exposes no delete, lock or void ability', function (string $ability): void {
    // There is no canonical code for any of the three, and no documented rule
    // describing the acts. CLAUDE.md section 29 requires that correction mechanisms
    // "follow documented business rules"; none exist (D-120).
    expect(method_exists(NotaryDeedPolicy::class, $ability))->toBeFalse();
})->with(['delete', 'forceDelete', 'restore', 'lock', 'void', 'supersede']);

it('offers the four assignable scopes for every deed capability', function (string $code): void {
    expect(app(PermissionScopeRules::class)->allowedFor($code))->toBe([
        DataScope::OWN,
        DataScope::ASSIGNED,
        DataScope::OFFICE,
        DataScope::ALL,
    ]);
})->with([
    'notary.deeds.view',
    'notary.deeds.create',
    'notary.deeds.update',
    'notary.deeds.review',
    'notary.deeds.approve',
    'notary.deeds.finalize',
    'notary.deeds.number',
]);

it('keeps notary.deeds.view_all out of the scope rules, because no such code exists', function (): void {
    // Unlike Project, Matter and Task, the Notary deed family has no `view_all`
    // code at all — so there is nothing here for D-090 to supersede.
    expect(app(PermissionRegistry::class)->all())
        ->not->toContain('notary.deeds.view_all');
});

/*
|--------------------------------------------------------------------------
| The visibility class carries no ranking
|--------------------------------------------------------------------------
*/

it('exposes no widest-scope helper', function (string $method): void {
    // D-028: scopes are predicates, never a ladder. The same assertion
    // MatterVisibility and TaskVisibility carry.
    expect(method_exists(NotaryDeedVisibility::class, $method))->toBeFalse();
})->with(['widest', 'rank', 'maxScope', 'highest']);

it('narrows a list query the same way it answers a record check', function (): void {
    // The record check *is* the list query — the failure mode where a record is
    // hidden from a listing yet still fetchable by id cannot arise.
    [$actor, $office] = deedActor(['notary.deeds.view'], DataScope::OWN);

    $colleague = User::factory()->for($office)->create();

    $mine = NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $actor))->create();
    NotaryDeed::factory()->forMatter(deedMatter($office, createdBy: $colleague))->create();

    $access = app(EffectiveAccessResolver::class)
        ->resolve($actor, 'notary.deeds.view');

    $visible = app(NotaryDeedVisibility::class)
        ->scope(NotaryDeed::query(), $actor, $access)
        ->pluck('id')
        ->all();

    expect($visible)->toBe([$mine->getKey()]);
});
