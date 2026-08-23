<?php

use App\Domains\MasterData\Enums\ServiceTypeDomain;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\Enums\MatterStatus;
use App\Domains\Project\Enums\ProjectPriority;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Domain and status vocabulary
|--------------------------------------------------------------------------
*/

it('publishes exactly the canonical matter domain vocabulary', function (): void {
    expect(MatterDomain::values())->toBe(['NOTARY', 'PPAT']);
});

it('keeps matter and service type domain vocabularies identical', function (): void {
    // The two enums are deliberately separate — Matter is not a master-data
    // concept — but they describe the same split, and the composite foreign key
    // joins on the stored value. A divergence must be a deliberate act, not an
    // accident nobody noticed.
    expect(MatterDomain::values())->toBe(ServiceTypeDomain::values());
});

it('offers no combined or aliased domain', function (): void {
    $names = array_map(fn (MatterDomain $case): string => $case->name, MatterDomain::cases());

    expect($names)->not->toContain('BOTH')
        ->and($names)->not->toContain('ANY')
        ->and(count($names))->toBe(2);
});

it('publishes exactly the canonical matter status vocabulary', function (): void {
    expect(MatterStatus::values())->toBe([
        'OPEN', 'IN_PROGRESS', 'WAITING', 'ON_HOLD', 'COMPLETED', 'CANCELLED', 'ARCHIVED',
    ]);
});

it('defines no transition logic on the status enum', function (): void {
    // D-102, following D-091: M4 authorizes who may change status, never which
    // change is legal. A `canTransitionTo()` from memory would be the failure
    // CLAUDE.md section 62 prohibits.
    $methods = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(MatterStatus::class))->getMethods(),
    );

    expect($methods)->not->toContain('canTransitionTo')
        ->and($methods)->not->toContain('transitions')
        ->and($methods)->not->toContain('next');
});

it('maps each domain to its own canonical permission namespace', function (): void {
    expect(MatterDomain::NOTARY->permissionNamespace())->toBe('notary.matters')
        ->and(MatterDomain::PPAT->permissionNamespace())->toBe('ppat.matters')
        ->and(MatterDomain::NOTARY->permission('create'))->toBe('notary.matters.create')
        ->and(MatterDomain::PPAT->permission('change_stage'))->toBe('ppat.matters.change_stage');
});

/*
|--------------------------------------------------------------------------
| Casts and relationships
|--------------------------------------------------------------------------
*/

it('casts the coded columns and the dates', function (): void {
    $matter = Matter::factory()->create([
        'domain' => MatterDomain::PPAT,
        'status' => MatterStatus::WAITING,
        'priority' => ProjectPriority::HIGH,
        'opened_at' => '2026-08-17',
        'completed_at' => '2026-08-17 10:00:00',
    ]);

    $fresh = $matter->fresh();

    expect($fresh->domain)->toBe(MatterDomain::PPAT)
        ->and($fresh->status)->toBe(MatterStatus::WAITING)
        ->and($fresh->priority)->toBe(ProjectPriority::HIGH)
        ->and($fresh->opened_at->toDateString())->toBe('2026-08-17')
        ->and($fresh->completed_at)->not->toBeNull();
});

it('reuses the shared priority vocabulary rather than duplicating it', function (): void {
    // ProjectPriority records that the ERD names `priority` on projects, matters
    // and tasks and defines the values exactly once. One vocabulary, one enum.
    expect(class_exists('App\\Domains\\Matter\\Enums\\MatterPriority'))->toBeFalse();
});

it('relates to its project, office, service type, pic, creator, and updater', function (): void {
    $actor = User::factory()->create();
    $matter = Matter::factory()->withServiceType()->createdBy($actor)->assignedTo($actor)->create([
        'updated_by' => $actor->getKey(),
    ]);

    expect($matter->project)->not->toBeNull()
        ->and($matter->office)->not->toBeNull()
        ->and($matter->serviceType)->not->toBeNull()
        ->and($matter->picUser->getKey())->toBe($actor->getKey())
        ->and($matter->createdBy->getKey())->toBe($actor->getKey())
        ->and($matter->updatedBy->getKey())->toBe($actor->getKey());
});

it('inherits its office from the parent project', function (): void {
    $project = Project::factory()->create();
    $matter = Matter::factory()->for($project)->create();

    expect($matter->office_id)->toBe($project->office_id);
});

/*
|--------------------------------------------------------------------------
| Identity is immutable; content is not
|--------------------------------------------------------------------------
*/

it('refuses to change the office', function (): void {
    $matter = Matter::factory()->create();
    $other = Office::factory()->create();

    expect(function () use ($matter, $other): void {
        $matter->office_id = $other->getKey();
        $matter->save();
    })->toThrow(RuntimeException::class);
});

it('refuses to change the project', function (): void {
    $matter = Matter::factory()->create();
    $other = Project::factory()->create();

    expect(function () use ($matter, $other): void {
        $matter->project_id = $other->getKey();
        $matter->save();
    })->toThrow(RuntimeException::class);
});

it('refuses to change the domain', function (): void {
    // The domain selects the capability namespace that authorizes the record, so
    // flipping it would reclassify work already done.
    $matter = Matter::factory()->domain(MatterDomain::NOTARY)->create();

    expect(function () use ($matter): void {
        $matter->domain = MatterDomain::PPAT;
        $matter->save();
    })->toThrow(RuntimeException::class);
});

it('withholds identity and lifecycle fields from mass assignment', function (): void {
    $matter = Matter::factory()->create();

    foreach ([
        'project_id', 'office_id', 'domain', 'status', 'pic_user_id',
        'created_by', 'updated_by', 'service_type_id',
    ] as $field) {
        expect($matter->isFillable($field))->toBeFalse($field);
    }
});

it('allows ordinary matter content to be corrected', function (): void {
    $matter = Matter::factory()->create();

    $matter->fill([
        'title' => 'Pekerjaan Uji Diperbarui',
        'priority' => ProjectPriority::URGENT,
        'notes' => 'Catatan uji',
    ]);
    $matter->save();

    $fresh = $matter->fresh();

    expect($fresh->title)->toBe('Pekerjaan Uji Diperbarui')
        ->and($fresh->priority)->toBe(ProjectPriority::URGENT)
        ->and($fresh->notes)->toBe('Catatan uji');
});

/*
|--------------------------------------------------------------------------
| No soft delete lifecycle
|--------------------------------------------------------------------------
*/

it('uses no soft deletes despite carrying the column', function (): void {
    // Adding the trait would install a global scope filtering every query —
    // including MatterVisibility — making "invisible because soft-deleted"
    // indistinguishable from "unreachable by scope", before the milestone that
    // owns archiving exists to decide that (D-102).
    expect(in_array(SoftDeletes::class, class_uses_recursive(Matter::class), true))->toBeFalse();
});

it('applies no global query scope', function (): void {
    expect((new Matter)->getGlobalScopes())->toBe([]);
});

it('ships no matter rows', function (): void {
    expect(Matter::query()->count())->toBe(0);
});
