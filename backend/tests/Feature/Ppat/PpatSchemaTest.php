<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Ppat\Enums\PpatDeedStatus;
use App\Domains\Ppat\Enums\PpatWarkahStatus;
use App\Domains\Ppat\Enums\PropertyType;
use App\Domains\Ppat\PropertyVisibility;
use App\Models\Document;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Party;
use App\Models\PpatDeed;
use App\Models\PpatMatter;
use App\Models\PpatWarkah;
use App\Models\PpatWarkahItem;
use App\Models\Project;
use App\Models\Property;
use App\Models\PropertyOwner;
use App\Models\User;
use App\Policies\PpatDeedPolicy;
use App\Policies\PropertyPolicy;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function ppatMatterIn(Office $office): Matter
{
    return Matter::factory()->for(Project::factory()->for($office)->create())->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::PPAT,
    ]);
}

/*
|--------------------------------------------------------------------------
| Table shape — transcribed from the ERD
|--------------------------------------------------------------------------
*/

it('carries exactly the canonical properties columns', function (): void {
    $columns = Schema::getColumnListing('properties');
    sort($columns);

    $expected = [
        'address', 'building_area', 'certificate_date', 'certificate_number', 'city',
        'created_at', 'created_by', 'deleted_at', 'district', 'id', 'land_area',
        'latitude', 'longitude', 'measurement_letter_date', 'measurement_letter_number',
        'office_id', 'postal_code', 'property_number', 'property_type', 'province',
        'right_type', 'status', 'updated_at', 'updated_by', 'village',
    ];
    sort($expected);

    expect($columns)->toBe($expected);
});

it('carries exactly the canonical ppat_deeds columns', function (): void {
    // The ERD gives PPAT deeds **one** document pointer, not the three notary_deeds
    // has: PPAT supporting material is the Warkah (D-121).
    $columns = Schema::getColumnListing('ppat_deeds');
    sort($columns);

    $expected = [
        'approved_at', 'approved_by', 'created_at', 'deed_date', 'deed_number',
        'deed_type_code', 'final_document_id', 'finalized_at', 'finalized_by', 'id',
        'locked_at', 'matter_id', 'office_id', 'reviewed_at', 'reviewed_by', 'status',
        'title', 'updated_at',
    ];
    sort($expected);

    expect($columns)->toBe($expected);
});

it('gives ppat_deeds neither the notary document pointers nor locked_by nor deleted_at', function (string $column): void {
    expect(Schema::hasColumn('ppat_deeds', $column))->toBeFalse();
})->with(['draft_document_id', 'minuta_document_id', 'locked_by', 'deleted_at']);

it('uses no soft delete on ppat_deeds', function (): void {
    expect(in_array(SoftDeletes::class, class_uses_recursive(PpatDeed::class), true))->toBeFalse();
});

it('soft deletes properties, because the ERD carries deleted_at for that table', function (): void {
    // Unlike a finalized legal record, a Property is reference data an office may
    // retire — and the ERD names the column.
    expect(in_array(SoftDeletes::class, class_uses_recursive(Property::class), true))->toBeTrue();
});

it('keeps the ERD singular warkah table name', function (): void {
    expect((new PpatWarkah)->getTable())->toBe('ppat_warkah')
        ->and(Schema::hasTable('ppat_warkahs'))->toBeFalse();
});

it('gives the warkah document junction no surrogate id', function (): void {
    // The ERD field list is `warkah_item_id document_id attached_at attached_by`.
    expect(Schema::hasColumn('ppat_warkah_documents', 'id'))->toBeFalse();
});

it('gives matter_properties a surrogate id and only created_at', function (): void {
    // The ERD gives this junction an `id` — unlike the document junctions — and names
    // no `updated_at`.
    expect(Schema::hasColumn('matter_properties', 'id'))->toBeTrue()
        ->and(Schema::hasColumn('matter_properties', 'updated_at'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Vocabularies: which are closed, which are open, which do not exist
|--------------------------------------------------------------------------
*/

it('constrains property_type, because the ERD gives a closed list', function (): void {
    expect(PropertyType::values())->toBe(['LAND', 'LAND_AND_BUILDING', 'APARTMENT_UNIT', 'OTHER']);
});

it('spells the apartment code as the ERD does', function (): void {
    // The M7 brief shortened it to APARTMENT. A stable machine code is only stable if
    // it is copied exactly.
    expect(PropertyType::tryFrom('APARTMENT_UNIT'))->not->toBeNull()
        ->and(PropertyType::tryFrom('APARTMENT'))->toBeNull();
});

it('leaves right_type open, because the ERD says codes may be used for example', function (): void {
    // Constraining it to five would assert Indonesian land law has five kinds of
    // right (D-121, CLAUDE.md section 62).
    $property = Property::factory()->rightType('SESUATU_YANG_LAIN')->create();

    expect($property->fresh()->right_type)->toBe('SESUATU_YANG_LAIN')
        ->and(class_exists('App\Domains\Ppat\Enums\RightType'))->toBeFalse();
});

it('leaves deed_type_code open, because the ERD calls the codes possible', function (): void {
    $deed = PpatDeed::factory()->create(['deed_type_code' => 'KODE_BEBAS']);

    expect($deed->fresh()->deed_type_code)->toBe('KODE_BEBAS')
        ->and(class_exists('App\Domains\Ppat\Enums\PpatDeedType'))->toBeFalse();
});

it('gives properties.status no vocabulary and no default', function (): void {
    // The ERD names the column and gives it no values. A default of ACTIVE would
    // assert a lifecycle (D-121).
    expect(Property::factory()->create()->status)->toBeNull()
        ->and(class_exists('App\Domains\Ppat\Enums\PropertyStatus'))->toBeFalse();
});

it('gives warkah items no status vocabulary, unlike the warkah itself', function (): void {
    // `ppat_warkah.status` gets five canonical values; `ppat_warkah_items.status` gets
    // none. An item-status vocabulary IS the verification rule, which is open question
    // three (O-041).
    expect(PpatWarkahItem::factory()->create()->status)->toBeNull()
        ->and(class_exists('App\Domains\Ppat\Enums\PpatWarkahItemStatus'))->toBeFalse();

    expect(PpatWarkahStatus::values())
        ->toBe(['INCOMPLETE', 'UNDER_REVIEW', 'COMPLETE', 'FINALIZED', 'ARCHIVED']);
});

it('separates reachable deed statuses from stored vocabulary', function (): void {
    expect(PpatDeedStatus::reachable())->toBe([
        PpatDeedStatus::DRAFT,
        PpatDeedStatus::UNDER_REVIEW,
        PpatDeedStatus::APPROVED,
        PpatDeedStatus::FINALIZED,
    ])->and(PpatDeedStatus::unreachable())->toBe([
        PpatDeedStatus::VOID,
        PpatDeedStatus::SUPERSEDED,
    ]);
});

it('separates reachable warkah statuses from stored vocabulary', function (): void {
    // FINALIZED and ARCHIVED are canonical values whose trigger is open question eight.
    expect(PpatWarkahStatus::unreachable())
        ->toBe([PpatWarkahStatus::FINALIZED, PpatWarkahStatus::ARCHIVED]);
});

/*
|--------------------------------------------------------------------------
| Office is structural
|--------------------------------------------------------------------------
*/

it('refuses a deed whose office differs from its matter', function (): void {
    $matter = Matter::factory()->state(['domain' => MatterDomain::PPAT])->create();

    expect(fn () => PpatDeed::factory()->create([
        'matter_id' => $matter->getKey(),
        'office_id' => Office::factory()->create()->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses a deed pointing at a cross-office document', function (): void {
    $matter = Matter::factory()->state(['domain' => MatterDomain::PPAT])->create();
    $foreign = Document::factory()->create();

    expect(fn () => PpatDeed::factory()->forMatter($matter)->create([
        'final_document_id' => $foreign->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses an owner link whose party is in another office', function (): void {
    $property = Property::factory()->create();
    $outsider = Party::factory()->create();

    expect($outsider->office_id)->not->toBe($property->office_id);

    expect(fn () => PropertyOwner::factory()->forProperty($property)->party($outsider)->create())
        ->toThrow(QueryException::class);
});

it('refuses a warkah whose office differs from its deed', function (): void {
    $deed = PpatDeed::factory()->create();

    expect(fn () => PpatWarkah::factory()->create([
        'ppat_deed_id' => $deed->getKey(),
        'office_id' => Office::factory()->create()->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses a warkah document from another office', function (): void {
    $item = PpatWarkahItem::factory()->create();
    $foreign = Document::factory()->create();
    $actor = User::factory()->create(['office_id' => $item->office_id]);

    expect(fn () => DB::table('ppat_warkah_documents')->insert([
        'warkah_item_id' => $item->getKey(),
        'document_id' => $foreign->getKey(),
        'office_id' => $item->office_id,
        'attached_at' => now(),
        'attached_by' => $actor->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses a matter_properties row crossing offices', function (): void {
    $matter = ppatMatterIn(Office::factory()->create());
    $elsewhere = Property::factory()->create();

    expect(fn () => DB::table('matter_properties')->insert([
        'id' => (string) Str::ulid(),
        'matter_id' => $matter->getKey(),
        'property_id' => $elsewhere->getKey(),
        'office_id' => $matter->office_id,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Uniqueness
|--------------------------------------------------------------------------
*/

it('refuses two deeds sharing a number within one office', function (): void {
    $matter = Matter::factory()->state(['domain' => MatterDomain::PPAT])->create();

    PpatDeed::factory()->forMatter($matter)->numbered('UJI-1')->create();

    expect(fn () => PpatDeed::factory()->forMatter($matter)->numbered('UJI-1')->create())
        ->toThrow(QueryException::class);
});

it('lets two offices use the same deed number', function (): void {
    PpatDeed::factory()->forMatter(Matter::factory()->state(['domain' => MatterDomain::PPAT])->create())
        ->numbered('UJI-1')->create();
    PpatDeed::factory()->forMatter(Matter::factory()->state(['domain' => MatterDomain::PPAT])->create())
        ->numbered('UJI-1')->create();

    expect(PpatDeed::query()->where('deed_number', 'UJI-1')->count())->toBe(2);
});

it('lets many deeds and properties stay unnumbered', function (): void {
    // No creation path allocates a number until M7.2 and M7.3.
    $matter = Matter::factory()->state(['domain' => MatterDomain::PPAT])->create();
    PpatDeed::factory()->forMatter($matter)->count(3)->create();
    Property::factory()->inOffice($matter->office_id)->count(3)->create();

    expect(PpatDeed::query()->whereNull('deed_number')->count())->toBe(3)
        ->and(Property::query()->whereNull('property_number')->count())->toBe(3);
});

it('refuses two properties sharing a number within one office', function (): void {
    $office = Office::factory()->create();

    Property::factory()->inOffice($office)->numbered('PROP-000001')->create();

    expect(fn () => Property::factory()->inOffice($office)->numbered('PROP-000001')->create())
        ->toThrow(QueryException::class);
});

it('allows one warkah per deed and refuses a second', function (): void {
    $deed = PpatDeed::factory()->create();

    PpatWarkah::factory()->forDeed($deed)->create();

    expect(fn () => PpatWarkah::factory()->forDeed($deed)->create())
        ->toThrow(QueryException::class);
});

it('allows one extension row per matter and refuses a second', function (): void {
    $matter = Matter::factory()->state(['domain' => MatterDomain::PPAT])->create();

    PpatMatter::factory()->forMatter($matter)->create();

    expect(fn () => PpatMatter::factory()->forMatter($matter)->create())
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| RESTRICT
|--------------------------------------------------------------------------
*/

it('refuses to delete a document a deed or a warkah item points at', function (): void {
    $matter = Matter::factory()->state(['domain' => MatterDomain::PPAT])->create();
    $document = Document::factory()->create(['office_id' => $matter->office_id]);

    PpatDeed::factory()->forMatter($matter)->create(['final_document_id' => $document->getKey()]);

    expect(fn () => $document->forceDelete())->toThrow(QueryException::class);
});

it('refuses to delete a party that owns a property', function (): void {
    $owner = PropertyOwner::factory()->create();

    expect(fn () => DB::table('parties')->where('id', $owner->party_id)->delete())
        ->toThrow(QueryException::class);
});

it('refuses to delete a deed that has a warkah', function (): void {
    $warkah = PpatWarkah::factory()->create();

    expect(fn () => DB::table('ppat_deeds')->where('id', $warkah->ppat_deed_id)->delete())
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Ownership history
|--------------------------------------------------------------------------
*/

it('allows several current owners of one property', function (): void {
    // The reason D-116 does not apply: `is_current` is a flag on many rows here, not
    // a pointer to one. A unique index would break co-ownership.
    $property = Property::factory()->create();

    PropertyOwner::factory()->forProperty($property)->share('50.00')->create();
    PropertyOwner::factory()->forProperty($property)->share('50.00')->create();

    expect($property->currentOwners()->count())->toBe(2);
});

it('enforces no percentage sum', function (): void {
    // Whether shares must total 100 is a rule about Indonesian co-ownership
    // (CLAUDE.md section 62). The column stores what the office records.
    $property = Property::factory()->create();

    PropertyOwner::factory()->forProperty($property)->share('70.00')->create();
    PropertyOwner::factory()->forProperty($property)->share('70.00')->create();

    expect($property->currentOwners()->count())->toBe(2);
});

it('refuses a percentage outside nought to a hundred', function (): void {
    // Arithmetic, not a legal rule. Enforced by a PostgreSQL CHECK and by the model
    // guard that holds the same rule on the SQLite connection the suite runs on.
    expect(fn () => PropertyOwner::factory()->share('150.00')->create())
        ->toThrow(RuntimeException::class, 'percentage');

    expect(fn () => PropertyOwner::factory()->share('-1.00')->create())
        ->toThrow(RuntimeException::class, 'percentage');
});

it('refuses a period that runs backwards', function (): void {
    expect(fn () => PropertyOwner::factory()->create([
        'effective_from' => '2026-06-30',
        'effective_until' => '2026-01-01',
        'is_current' => false,
    ]))->toThrow(RuntimeException::class, 'forwards');
});

it('refuses a row that has ended yet claims to be current', function (): void {
    // The denormalization guard: `is_current` must not disagree with
    // `effective_until`.
    expect(fn () => PropertyOwner::factory()->create([
        'is_current' => true,
        'effective_until' => '2026-06-30',
    ]))->toThrow(RuntimeException::class);
});

it('closes a link rather than rewriting it', function (): void {
    // History is added, never overwritten (CLAUDE.md section 63).
    $owner = PropertyOwner::factory()->create();

    expect(fn () => $owner->forceFill(['party_id' => Party::factory()->create()->getKey()])->save())
        ->toThrow(RuntimeException::class, 'immutable');

    // **Re-fetched, because `forceFill` mutated the instance above.** The refused save
    // left the changed `party_id` in memory, so reusing the same object would throw
    // again on a guard this half of the test is not about.
    $owner = $owner->fresh();

    $owner->forceFill(['is_current' => false, 'effective_until' => '2026-06-30'])->save();

    expect($owner->fresh()->is_current)->toBeFalse()
        ->and($owner->fresh()->effective_until?->toDateString())->toBe('2026-06-30');
});

/*
|--------------------------------------------------------------------------
| Completeness counts documents, never statuses
|--------------------------------------------------------------------------
*/

it('reports an empty warkah as nought per cent, not a hundred', function (): void {
    // A bundle nobody has listed anything for has collected nothing, and 100 would be
    // the most misleading answer available.
    expect(PpatWarkah::factory()->create()->computeCompleteness())->toBe(0);
});

it('counts items with a document attached', function (): void {
    $warkah = PpatWarkah::factory()->create();

    PpatWarkahItem::factory()->forWarkah($warkah)->withDocument()->create();
    PpatWarkahItem::factory()->forWarkah($warkah)->withDocument()->create();
    PpatWarkahItem::factory()->forWarkah($warkah)->create();
    PpatWarkahItem::factory()->forWarkah($warkah)->create();

    expect($warkah->computeCompleteness())->toBe(50);
});

it('ignores item status entirely when counting', function (): void {
    // The ruling: completeness counts what has been collected, and no item-status
    // vocabulary exists to count instead (D-121).
    $warkah = PpatWarkah::factory()->create();

    $withFile = PpatWarkahItem::factory()->forWarkah($warkah)->withDocument()->create();
    $withoutFile = PpatWarkahItem::factory()->forWarkah($warkah)->create();

    // Whatever an office writes into `status`, the count does not consult it.
    $withFile->forceFill(['status' => 'REJECTED'])->save();
    $withoutFile->forceFill(['status' => 'VERIFIED'])->save();

    expect($warkah->computeCompleteness())->toBe(50);
});

it('stores the recalculated percentage without touching the status', function (): void {
    $warkah = PpatWarkah::factory()->create();
    PpatWarkahItem::factory()->forWarkah($warkah)->withDocument()->create();

    $warkah->recalculateCompleteness();

    expect($warkah->fresh()->completeness_percentage)->toBe(100)
        // 100% does not mean COMPLETE. Which governs sufficiency is open question
        // three, so neither is derived from the other.
        ->and($warkah->fresh()->status)->toBe(PpatWarkahStatus::INCOMPLETE);
});

/*
|--------------------------------------------------------------------------
| Model guards
|--------------------------------------------------------------------------
*/

it('refuses to move a deed between offices or matters', function (string $column): void {
    $deed = PpatDeed::factory()->create();

    $other = $column === 'office_id'
        ? Office::factory()->create()->getKey()
        : Matter::factory()->state(['domain' => MatterDomain::PPAT])->create()->getKey();

    expect(fn () => $deed->forceFill([$column => $other])->save())
        ->toThrow(RuntimeException::class, 'immutable');
})->with(['office_id', 'matter_id']);

it('refuses half a recorded act on a deed', function (string $act): void {
    $deed = PpatDeed::factory()->create();

    expect(fn () => $deed->forceFill(["{$act}_at" => now()])->save())
        ->toThrow(RuntimeException::class, 'pair');
})->with(['reviewed', 'approved', 'finalized']);

it('keeps status, the number and the act pairs out of mass assignment', function (): void {
    $deed = PpatDeed::factory()->create();

    $deed->fill([
        'status' => PpatDeedStatus::FINALIZED->value,
        'deed_number' => 'UJI-9',
        'approved_at' => now(),
        'locked_at' => now(),
    ]);

    expect($deed->status)->toBe(PpatDeedStatus::DRAFT)
        ->and($deed->deed_number)->toBeNull()
        ->and($deed->approved_at)->toBeNull()
        ->and($deed->locked_at)->toBeNull();
});

it('refuses to move a property between offices', function (): void {
    $property = Property::factory()->create();

    expect(fn () => $property->forceFill(['office_id' => Office::factory()->create()->getKey()])->save())
        ->toThrow(RuntimeException::class, 'immutable');
});

it('lets a property number be stamped once and never changed', function (): void {
    // The M3.2 shape: null to a reference is permitted because M7.3 stamps it on a
    // record that already exists; every change after that is refused.
    $property = Property::factory()->create();

    $property->forceFill(['property_number' => 'PROP-000001'])->save();

    expect($property->fresh()->property_number)->toBe('PROP-000001');

    expect(fn () => $property->forceFill(['property_number' => 'PROP-000002'])->save())
        ->toThrow(RuntimeException::class, 'immutable');
});

/*
|--------------------------------------------------------------------------
| Relations
|--------------------------------------------------------------------------
*/

it('reaches the whole PPAT chain', function (): void {
    $office = Office::factory()->create();
    $matter = ppatMatterIn($office);

    PpatMatter::factory()->forMatter($matter)->create();

    $deed = PpatDeed::factory()->forMatter($matter)->create();
    $warkah = PpatWarkah::factory()->forDeed($deed)->create();
    $item = PpatWarkahItem::factory()->forWarkah($warkah)->withDocument()->create();

    $property = Property::factory()->inOffice($office)->create();

    DB::table('matter_properties')->insert([
        'id' => (string) Str::ulid(),
        'matter_id' => $matter->getKey(),
        'property_id' => $property->getKey(),
        'office_id' => $office->getKey(),
        'role_code' => 'TRANSACTION_OBJECT',
        'created_at' => now(),
    ]);

    expect($deed->matter->is($matter))->toBeTrue()
        ->and($deed->warkah->is($warkah))->toBeTrue()
        ->and($warkah->items()->count())->toBe(1)
        ->and($item->documents()->count())->toBe(1)
        ->and($matter->ppatDeeds()->count())->toBe(1)
        ->and($matter->ppatExtension)->not->toBeNull()
        ->and($matter->properties()->count())->toBe(1)
        ->and($property->matters()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('registers no new permission', function (): void {
    // Every PPAT and Property code has been canonical since M1.2.
    expect(app(PermissionRegistry::class)->all())->toHaveCount(177);
});

it('adds no code for an act nobody has documented', function (string $code): void {
    expect(app(PermissionRegistry::class)->all())->not->toContain($code);
})->with([
    'ppat.taxes.view',
    'ppat.taxes.manage',
    'ppat.deeds.delete',
    'ppat.deeds.void',
    'ppat.deeds.lock',
    'ppat.warkah.delete',
    'properties.delete',
    'properties.ownership.create',
    'ppat.protocol.view',
]);

it('gives property two scopes rather than four', function (string $code): void {
    // Office-owned reference data — the Party (D-080) and Service Type (D-106)
    // answer, not the Project one.
    expect(app(PermissionScopeRules::class)->allowedFor($code))
        ->toBe([DataScope::OFFICE, DataScope::ALL]);
})->with([
    'properties.view',
    'properties.create',
    'properties.update',
    'properties.archive',
    'properties.ownership.view',
    'properties.ownership.update',
]);

it('gives ppat deeds and warkah all four assignable scopes', function (string $code): void {
    expect(app(PermissionScopeRules::class)->allowedFor($code))->toBe([
        DataScope::OWN, DataScope::ASSIGNED, DataScope::OFFICE, DataScope::ALL,
    ]);
})->with([
    'ppat.deeds.view',
    'ppat.deeds.number',
    'ppat.warkah.view',
    'ppat.warkah.finalize',
]);

it('exposes no delete, lock or void ability on the deed policy', function (string $ability): void {
    expect(method_exists(PpatDeedPolicy::class, $ability))->toBeFalse();
})->with(['delete', 'forceDelete', 'restore', 'lock', 'void', 'supersede']);

it('exposes no delete ability on the property policy', function (string $ability): void {
    expect(method_exists(PropertyPolicy::class, $ability))->toBeFalse();
})->with(['delete', 'forceDelete', 'restore']);

it('keeps ownership a separate capability from the property itself', function (): void {
    // Reading a Property does not read its chain of title.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'properties.view', DataScope::OFFICE);
    $actor = $actor->fresh();

    $property = Property::factory()->inOffice($office)->create();
    $policy = app(PropertyPolicy::class);

    expect($policy->view($actor, $property))->toBeTrue()
        ->and($policy->viewOwnership($actor, $property))->toBeFalse()
        ->and($policy->updateOwnership($actor, $property))->toBeFalse();
});

it('reaches no property on an OWN grant, because the scope is withheld', function (): void {
    // The dead control D-080 named: a grant the resolver cannot honour.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'properties.view', DataScope::OWN);
    $actor = $actor->fresh();

    $property = Property::factory()->inOffice($office)->create();

    expect(app(PropertyPolicy::class)->view($actor, $property))->toBeFalse()
        ->and(app(PropertyPolicy::class)->viewAny($actor))->toBeFalse();
});

it('does not let a matter capability reach a property', function (): void {
    // A whereHas('matters') branch in PropertyVisibility would make
    // `ppat.matters.view` a silent superset of `properties.view`.
    expect(method_exists(PropertyVisibility::class, 'scopeThroughMatters'))->toBeFalse();

    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'ppat.matters.view', DataScope::ALL);
    $actor = $actor->fresh();

    expect(app(PropertyPolicy::class)->view($actor, Property::factory()->inOffice($office)->create()))
        ->toBeFalse();
});

it('refuses a deed on a notary matter', function (): void {
    // The route decides the namespace (D-101) and the record must agree with it.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'ppat.deeds.create', DataScope::ALL);
    grantPermissionScope($actor, 'ppat.matters.view', DataScope::ALL);
    $actor = $actor->fresh();

    $notary = Matter::factory()->for(Project::factory()->for($office)->create())->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);

    expect(app(PpatDeedPolicy::class)->create($actor, $notary))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| What M7.1 deliberately did not build
|--------------------------------------------------------------------------
*/

it('creates no tax, register or protocol table', function (string $table): void {
    // Taxes have no capability at all (O-040); registers and protocol are batch 11
    // (O-042). Both are outside M7 entirely.
    expect(Schema::hasTable($table))->toBeFalse();
})->with([
    'ppat_tax_records',
    'ppat_register_entries',
    'notary_register_entries',
    'protocol_records',
    'ppat_protocols',
]);

it('does not build the document junction it just unblocked', function (): void {
    // D-118 recorded `ppat_deed_documents` as blocked because `ppat_deeds` did not
    // exist. M7.1 removes the obstacle and leaves the surface to whoever wants it.
    expect(Schema::hasTable('ppat_deed_documents'))->toBeFalse();
});

it('improvises no audit store', function (string $table): void {
    expect(Schema::hasTable($table))->toBeFalse();
})->with(['audit_logs', 'activities', 'ppat_deed_activities']);
