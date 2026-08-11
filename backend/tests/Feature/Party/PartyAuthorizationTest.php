<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Models\Company;
use App\Models\Individual;
use App\Models\Office;
use App\Models\Party;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\IndividualPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function individualIn(Office $office): Individual
{
    return Individual::factory()->for(Party::factory()->individual()->for($office), 'party')->create();
}

function companyIn(Office $office): Company
{
    return Company::factory()->for(Party::factory()->company()->for($office), 'party')->create();
}

$policy = fn (): IndividualPolicy => app(IndividualPolicy::class);
$companyPolicy = fn (): CompanyPolicy => app(CompanyPolicy::class);

/*
|--------------------------------------------------------------------------
| OFFICE and ALL are the only predicates that reach a Party
|--------------------------------------------------------------------------
*/

it('lets an OFFICE-scoped actor view an individual in their own office', function () use ($policy): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.view', DataScope::OFFICE);

    expect($policy()->view($actor->fresh(), individualIn($office)))->toBeTrue();
});

it('denies an OFFICE-scoped actor an individual in another office', function () use ($policy): void {
    $actor = User::factory()->for(Office::factory()->create())->create();
    grantPermissionScope($actor, 'parties.view', DataScope::OFFICE);

    expect($policy()->view($actor->fresh(), individualIn(Office::factory()->create())))->toBeFalse();
});

it('lets an ALL-scoped actor view an individual in another office', function () use ($policy): void {
    $actor = User::factory()->for(Office::factory()->create())->create();
    grantPermissionScope($actor, 'parties.view', DataScope::ALL);

    expect($policy()->view($actor->fresh(), individualIn(Office::factory()->create())))->toBeTrue();
});

it('grants nothing for OWN, ASSIGNED, or TEAM', function (string $scope) use ($policy): void {
    // OWN must not become created_by; ASSIGNED has nothing to match; TEAM must
    // never alias to OFFICE. All three fail closed (D-080).
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.view', DataScope::from($scope));

    $own = individualIn($office);

    expect($policy()->view($actor->fresh(), $own))->toBeFalse()
        ->and($policy()->viewAny($actor->fresh()))->toBeFalse();
})->with(['OWN', 'ASSIGNED', 'TEAM']);

it('does not treat the creating user as an owner', function () use ($policy): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.view', DataScope::OWN);

    $party = Party::factory()->individual()->for($office)->create(['created_by' => $actor->getKey()]);
    $individual = Individual::factory()->for($party, 'party')->create();

    // Typing in a record is not a claim on the person it describes.
    expect($policy()->view($actor->fresh(), $individual))->toBeFalse();
});

it('unions scopes across roles rather than ranking them', function () use ($policy): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.view', DataScope::OWN);
    grantPermissionScope($actor, 'parties.view', DataScope::OFFICE);

    // {OWN, OFFICE} reaches the office records because OFFICE is present, not
    // because it outranks OWN (D-028).
    expect($policy()->view($actor->fresh(), individualIn($office)))->toBeTrue();
});

it('denies everything when the permission is not granted at all', function () use ($policy): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    expect($policy()->view($actor->fresh(), individualIn($office)))->toBeFalse()
        ->and($policy()->viewAny($actor->fresh()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Creation is judged against the destination Office
|--------------------------------------------------------------------------
*/

it('lets an OFFICE-scoped actor create in their own office', function () use ($policy): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.create', DataScope::OFFICE);

    expect($policy()->create($actor->fresh(), $office->getKey()))->toBeTrue();
});

it('denies an OFFICE-scoped actor creating in another office', function () use ($policy): void {
    $actor = User::factory()->for(Office::factory()->create())->create();
    grantPermissionScope($actor, 'parties.create', DataScope::OFFICE);

    expect($policy()->create($actor->fresh(), Office::factory()->create()->getKey()))->toBeFalse();
});

it('lets an ALL-scoped actor create in another office', function () use ($policy): void {
    $actor = User::factory()->for(Office::factory()->create())->create();
    grantPermissionScope($actor, 'parties.create', DataScope::ALL);

    expect($policy()->create($actor->fresh(), Office::factory()->create()->getKey()))->toBeTrue();
});

it('grants no creation for OWN, ASSIGNED, or TEAM', function (string $scope) use ($policy): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.create', DataScope::from($scope));

    expect($policy()->create($actor->fresh(), $office->getKey()))->toBeFalse();
})->with(['OWN', 'ASSIGNED', 'TEAM']);

/*
|--------------------------------------------------------------------------
| Company lifecycle uses companies.*, not parties.*
|--------------------------------------------------------------------------
*/

it('authorizes company lifecycle with companies.view at OFFICE', function () use ($companyPolicy): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'companies.view', DataScope::OFFICE);

    expect($companyPolicy()->view($actor->fresh(), companyIn($office)))->toBeTrue();
});

it('does not let parties.view alone reach a company', function () use ($companyPolicy): void {
    // One ordinary mutation, one permission — Company lifecycle is companies.*
    // even though a Party row lives inside the aggregate (D-078).
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.view', DataScope::ALL);

    expect($companyPolicy()->view($actor->fresh(), companyIn($office)))->toBeFalse();
});

it('denies a company in another office to an OFFICE-scoped actor', function () use ($companyPolicy): void {
    $actor = User::factory()->for(Office::factory()->create())->create();
    grantPermissionScope($actor, 'companies.view', DataScope::OFFICE);

    expect($companyPolicy()->view($actor->fresh(), companyIn(Office::factory()->create())))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Two-tier sensitive identity — D-082
|--------------------------------------------------------------------------
*/

it('does not let parties.view open the identity surface', function () use ($policy): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.view', DataScope::OFFICE);

    expect($policy()->viewIdentity($actor->fresh(), individualIn($office)))->toBeFalse();
});

it('opens the identity surface without revealing either identifier', function () use ($policy): void {
    // Tier 1 alone. Access to the surface is not access to the values.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.identity.view', DataScope::OFFICE);

    $individual = individualIn($office);

    expect($policy()->viewIdentity($actor->fresh(), $individual))->toBeTrue()
        ->and($policy()->viewFullNik($actor->fresh(), $individual))->toBeFalse()
        ->and($policy()->viewFullNpwp($actor->fresh(), $individual))->toBeFalse();
});

it('reveals NIK without revealing NPWP', function () use ($policy): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.identity.view', DataScope::OFFICE);
    grantPermissionScope($actor, 'parties.identity.nik.view_full', DataScope::OFFICE);

    $individual = individualIn($office);

    expect($policy()->viewFullNik($actor->fresh(), $individual))->toBeTrue()
        ->and($policy()->viewFullNpwp($actor->fresh(), $individual))->toBeFalse();
});

it('reveals NPWP without revealing NIK', function () use ($policy): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.identity.view', DataScope::OFFICE);
    grantPermissionScope($actor, 'parties.identity.npwp.view_full', DataScope::OFFICE);

    $individual = individualIn($office);

    expect($policy()->viewFullNpwp($actor->fresh(), $individual))->toBeTrue()
        ->and($policy()->viewFullNik($actor->fresh(), $individual))->toBeFalse();
});

it('does not let identity.update imply any reveal', function () use ($policy): void {
    // Writing a value is not licence to read a different one.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.identity.update', DataScope::OFFICE);

    $individual = individualIn($office);

    expect($policy()->updateIdentity($actor->fresh(), $individual))->toBeTrue()
        ->and($policy()->viewFullNik($actor->fresh(), $individual))->toBeFalse()
        ->and($policy()->viewFullNpwp($actor->fresh(), $individual))->toBeFalse();
});

it('requires the identity surface as well as the field permission', function () use ($policy): void {
    // A permission to see one field of a surface the actor may not open would
    // be incoherent.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.identity.nik.view_full', DataScope::OFFICE);

    expect($policy()->viewFullNik($actor->fresh(), individualIn($office)))->toBeFalse();
});

it('does not let companies.view reveal the company tax identifier', function () use ($companyPolicy): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'companies.view', DataScope::ALL);

    expect($companyPolicy()->viewFullTaxId($actor->fresh(), companyIn($office)))->toBeFalse();
});

it('reveals the company tax identifier through the canonical NPWP permission', function () use ($companyPolicy): void {
    // The identity surface belongs to the aggregate, so a Company NPWP answers
    // to parties.identity.npwp.view_full. No companies.identity.* family exists.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'parties.identity.view', DataScope::OFFICE);
    grantPermissionScope($actor, 'parties.identity.npwp.view_full', DataScope::OFFICE);

    expect($companyPolicy()->viewFullTaxId($actor->fresh(), companyIn($office)))->toBeTrue();
});

it('applies the office boundary to identity reveal too', function () use ($policy): void {
    $actor = User::factory()->for(Office::factory()->create())->create();
    grantPermissionScope($actor, 'parties.identity.view', DataScope::OFFICE);
    grantPermissionScope($actor, 'parties.identity.nik.view_full', DataScope::OFFICE);

    expect($policy()->viewFullNik($actor->fresh(), individualIn(Office::factory()->create())))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| No role-name or direct-package authorization
|--------------------------------------------------------------------------
*/

it('gives a role named SUPER_ADMIN no party authorization', function () use ($policy): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    $actor->assignRole(makeRole('SUPER_ADMIN'));

    expect($actor->fresh()->hasRole('SUPER_ADMIN'))->toBeTrue()
        ->and($policy()->view($actor->fresh(), individualIn($office)))->toBeFalse()
        ->and($policy()->viewAny($actor->fresh()))->toBeFalse();
});

it('ignores a direct package permission grant', function () use ($policy): void {
    // D-041: direct user-permission grants never participate in first-party
    // authorization, no matter what the package thinks.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    $actor->givePermissionTo(makePermission('parties.view'));

    expect($policy()->view($actor->fresh(), individualIn($office)))->toBeFalse();
});
