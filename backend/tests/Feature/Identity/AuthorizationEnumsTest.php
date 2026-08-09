<?php

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\AccessSource;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;

/**
 * The authorization vocabulary. No database: these are value types, and a test
 * that needed a schema would mean one of them had grown a dependency it should
 * not have.
 */
it('declares exactly the five canonical Data Scopes in documentation order', function (): void {
    expect(array_column(DataScope::cases(), 'value'))
        ->toBe(['OWN', 'ASSIGNED', 'TEAM', 'OFFICE', 'ALL']);
});

it('declares exactly ALLOW and DENY as override effects', function (): void {
    expect(array_column(UserPermissionEffect::cases(), 'value'))->toBe(['ALLOW', 'DENY']);
});

it('declares the three resolution sources', function (): void {
    expect(array_column(AccessSource::cases(), 'value'))->toBe(['NONE', 'ROLE', 'OVERRIDE']);
});

it('rejects a value that is not a canonical Data Scope', function (): void {
    expect(DataScope::tryFrom('EVERYTHING'))->toBeNull()
        ->and(DataScope::tryFrom('own'))->toBeNull();
});

it('rejects a value that is not a canonical effect', function (): void {
    expect(UserPermissionEffect::tryFrom('PERMIT'))->toBeNull()
        ->and(UserPermissionEffect::tryFrom('allow'))->toBeNull();
});

it('sorts scopes into documentation order', function (): void {
    $shuffled = [DataScope::ALL, DataScope::TEAM, DataScope::OWN, DataScope::OFFICE, DataScope::ASSIGNED];

    expect(DataScope::orderCanonically($shuffled))->toBe(DataScope::cases());
});

it('sorts the same set identically no matter how it arrives', function (): void {
    $first = DataScope::orderCanonically([DataScope::OFFICE, DataScope::OWN]);
    $second = DataScope::orderCanonically([DataScope::OWN, DataScope::OFFICE]);

    expect($first)->toBe($second)
        ->and($first)->toBe([DataScope::OWN, DataScope::OFFICE]);
});

it('orders an empty set without complaint', function (): void {
    expect(DataScope::orderCanonically([]))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| No privilege ranking
|--------------------------------------------------------------------------
|
| Data Scopes are predicates, not security levels (D-028). The temptation to
| collapse a user's scopes to the "widest" one is exactly what these assert
| against: no such method exists to reach for.
|
*/

it('exposes no scope ranking API anywhere in the authorization layer', function (): void {
    $forbidden = [
        'widest', 'max', 'min', 'rank', 'level', 'weight', 'priority',
        'higherThan', 'lowerThan', 'greaterThan', 'isBroaderThan', 'isNarrowerThan',
        'compare', 'compareTo', 'collapse', 'reduce', 'strongest',
    ];

    $classes = [DataScope::class, EffectiveAccess::class, EffectiveAccessResolver::class];

    foreach ($classes as $class) {
        $methods = array_map(
            fn (ReflectionMethod $method): string => strtolower($method->getName()),
            (new ReflectionClass($class))->getMethods()
        );

        foreach ($forbidden as $name) {
            expect($methods)->not->toContain(strtolower($name));
        }
    }
});

it('gives the canonical order no arithmetic meaning', function (): void {
    // Ordering exists so output is stable. Nothing may read a later case as
    // stronger, so the enum stays a plain string enum with no backing integers
    // that an implementer could start comparing.
    foreach (DataScope::cases() as $case) {
        expect($case->value)->toBeString();
    }
});

/*
|--------------------------------------------------------------------------
| EffectiveAccess construction
|--------------------------------------------------------------------------
*/

it('carries no scopes when denied', function (): void {
    $access = EffectiveAccess::denied();

    expect($access->granted)->toBeFalse()
        ->and($access->scopes)->toBe([])
        ->and($access->source)->toBe(AccessSource::NONE);
});

it('records that a denial came from an override', function (): void {
    $access = EffectiveAccess::denied(AccessSource::OVERRIDE);

    expect($access->granted)->toBeFalse()
        ->and($access->scopes)->toBe([])
        ->and($access->source)->toBe(AccessSource::OVERRIDE);
});

it('de-duplicates and orders role scopes', function (): void {
    $access = EffectiveAccess::fromRoles([
        DataScope::OFFICE,
        DataScope::OWN,
        DataScope::OFFICE,
    ]);

    expect($access->granted)->toBeTrue()
        ->and($access->source)->toBe(AccessSource::ROLE)
        ->and($access->scopeValues())->toBe(['OWN', 'OFFICE']);
});

it('treats an empty role scope set as a denial rather than an empty grant', function (): void {
    $access = EffectiveAccess::fromRoles([]);

    expect($access->granted)->toBeFalse()
        ->and($access->scopes)->toBe([]);
});

it('carries exactly the override scope when granted by an override', function (): void {
    $access = EffectiveAccess::fromOverride(DataScope::ASSIGNED);

    expect($access->granted)->toBeTrue()
        ->and($access->scopeValues())->toBe(['ASSIGNED'])
        ->and($access->source)->toBe(AccessSource::OVERRIDE);
});

it('answers whether a scope is present', function (): void {
    $access = EffectiveAccess::fromRoles([DataScope::OWN, DataScope::OFFICE]);

    expect($access->hasScope(DataScope::OWN))->toBeTrue()
        ->and($access->hasScope(DataScope::OFFICE))->toBeTrue()
        ->and($access->hasScope(DataScope::ALL))->toBeFalse();
});

it('cannot be constructed except through its named factories', function (): void {
    expect((new ReflectionClass(EffectiveAccess::class))->getConstructor()->isPrivate())->toBeTrue();
});
