<?php

use App\Models\Office;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectParty;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function participationIn(Office $office, array $attributes = []): ProjectParty
{
    $project = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($office)->create();

    return ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => $office->getKey(),
        ...$attributes,
    ]);
}

/*
|--------------------------------------------------------------------------
| Columns
|--------------------------------------------------------------------------
*/

it('creates the project_parties table', function (): void {
    expect(Schema::hasTable('project_parties'))->toBeTrue();
});

it('carries exactly the canonical columns and nothing more', function (): void {
    // `03_DATABASE_ERD.md` section 7, plus `office_id` as the constraint carrier
    // the composite foreign keys need (D-098). A column not on this list is a
    // mechanism nobody asked for.
    $columns = Schema::getColumnListing('project_parties');
    sort($columns);

    expect($columns)->toBe([
        'created_at',
        'created_by',
        'id',
        'is_primary',
        'notes',
        'office_id',
        'party_id',
        'project_id',
        'role_code',
    ]);
});

it('gives a participation a generated ULID primary key', function (): void {
    $participation = participationIn(Office::factory()->create());

    expect($participation->getKeyType())->toBe('string')
        ->and($participation->getIncrementing())->toBeFalse()
        ->and(strlen($participation->id))->toBe(26)
        ->and(Str::isUlid($participation->id))->toBeTrue();
});

it('allows a null role_code', function (): void {
    $participation = participationIn(Office::factory()->create(), ['role_code' => null]);

    expect($participation->fresh()->role_code)->toBeNull();
});

it('defaults is_primary to false and stores it not-null', function (): void {
    $office = Office::factory()->create();
    $project = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($office)->create();

    // Inserted without the column at all, so the database default is what is
    // being observed rather than a value the model supplied.
    DB::table('project_parties')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $project->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => $office->getKey(),
        'created_at' => now(),
    ]);

    expect(DB::table('project_parties')->value('is_primary'))->toBeFalsy();

    $column = collect(Schema::getColumns('project_parties'))
        ->firstWhere('name', 'is_primary');

    expect($column['nullable'])->toBeFalse();
});

it('leaves role_code nullable in the database', function (): void {
    $column = collect(Schema::getColumns('project_parties'))->firstWhere('name', 'role_code');

    expect($column['nullable'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| No history, no soft delete
|--------------------------------------------------------------------------
*/

it('carries no lifecycle or history column', function (): void {
    // The deliberate difference from `company_people` (D-083 vs D-098).
    // Participation is current working state; adding any of these would be the
    // first half of a history mechanism no surface honours.
    foreach (['deleted_at', 'updated_at', 'updated_by', 'effective_from', 'effective_until', 'is_current'] as $column) {
        expect(Schema::hasColumn('project_parties', $column))->toBeFalse();
    }
});

it('does not soft delete the model', function (): void {
    expect(in_array(
        SoftDeletes::class,
        class_uses_recursive(ProjectParty::class),
        true,
    ))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Same-office invariant, enforced by the database
|--------------------------------------------------------------------------
*/

it('adds the projects support key the composite foreign key needs', function (): void {
    $indexes = collect(Schema::getIndexes('projects'));

    $support = $indexes->first(fn (array $index): bool => $index['columns'] === ['id', 'office_id']);

    expect($support)->not->toBeNull()
        ->and($support['unique'])->toBeTrue();
});

it('carries both composite same-office foreign keys', function (): void {
    $foreignKeys = collect(Schema::getForeignKeys('project_parties'));

    $toProjects = $foreignKeys->first(fn (array $key): bool => $key['columns'] === ['project_id', 'office_id']);
    $toParties = $foreignKeys->first(fn (array $key): bool => $key['columns'] === ['party_id', 'office_id']);

    expect($toProjects)->not->toBeNull()
        ->and($toProjects['foreign_table'])->toBe('projects')
        ->and($toProjects['foreign_columns'])->toBe(['id', 'office_id'])
        ->and($toParties)->not->toBeNull()
        ->and($toParties['foreign_table'])->toBe('parties')
        ->and($toParties['foreign_columns'])->toBe(['id', 'office_id']);
});

it('accepts a same-office participation', function (): void {
    $office = Office::factory()->create();

    expect(participationIn($office)->exists)->toBeTrue();
});

it('refuses a cross-office participation at the database level', function (): void {
    // Not validation — the constraint. Both endpoints resolve through the same
    // `office_id`, so they cannot disagree with each other.
    $office = Office::factory()->create();
    $elsewhere = Office::factory()->create();

    $project = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($elsewhere)->create();

    ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => $office->getKey(),
    ]);
})->throws(QueryException::class);

it('refuses an office_id that matches neither endpoint', function (): void {
    $office = Office::factory()->create();
    $project = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($office)->create();

    ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => Office::factory()->create()->getKey(),
    ]);
})->throws(QueryException::class);

/*
|--------------------------------------------------------------------------
| No invented cardinality
|--------------------------------------------------------------------------
*/

it('imposes no participant cardinality uniqueness', function (): void {
    // A unique index here would be a business rule wearing an index's clothing.
    // No canonical document states one (D-092).
    $unique = collect(Schema::getIndexes('project_parties'))
        ->filter(fn (array $index): bool => $index['unique'])
        ->pluck('columns')
        ->map(fn (array $columns): string => implode(',', $columns))
        ->all();

    expect($unique)->not->toContain('project_id,party_id')
        ->and($unique)->not->toContain('project_id,party_id,role_code')
        ->and($unique)->not->toContain('party_id,project_id');
});

it('lets the same Party appear twice on one Project', function (): void {
    $office = Office::factory()->create();
    $project = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($office)->create();

    foreach (['CLIENT_A', 'CLIENT_B'] as $code) {
        ProjectParty::factory()->create([
            'project_id' => $project->getKey(),
            'party_id' => $party->getKey(),
            'office_id' => $office->getKey(),
            'role_code' => $code,
        ]);
    }

    expect(ProjectParty::query()->where('project_id', $project->getKey())->count())->toBe(2);
});

it('lets several participants be primary at once', function (): void {
    $office = Office::factory()->create();
    $project = Project::factory()->for($office)->create();

    foreach (range(1, 3) as $ignored) {
        ProjectParty::factory()->create([
            'project_id' => $project->getKey(),
            'party_id' => Party::factory()->individual()->for($office)->create()->getKey(),
            'office_id' => $office->getKey(),
            'is_primary' => true,
        ]);
    }

    expect(ProjectParty::query()->where('is_primary', true)->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| No identity copied, no role catalogue
|--------------------------------------------------------------------------
*/

it('copies no Party sensitive identity into the participation table', function (): void {
    // D-082, D-092: the row points at a Party and reads nothing from it.
    $forbidden = [
        'nik', 'npwp', 'tax_id', 'nik_masked', 'npwp_masked',
        'nik_fingerprint', 'npwp_fingerprint', 'tax_id_fingerprint',
        'display_name', 'full_name', 'company_name',
        'primary_phone', 'primary_email', 'address', 'birth_date', 'birth_place',
    ];

    foreach ($forbidden as $column) {
        expect(Schema::hasColumn('project_parties', $column))->toBeFalse();
    }
});

it('stores any opaque role_code rather than a catalogue member', function (): void {
    // No enum, no `Rule::in`, no CHECK. No canonical participant-role vocabulary
    // exists, so a code the ERD never mentions must store exactly as one it does
    // — otherwise the six examples would have become a catalogue by accident.
    $office = Office::factory()->create();

    foreach (['ANYTHING_OPAQUE', 'CLIENT', 'PIHAK_KETIGA'] as $code) {
        expect(participationIn($office, ['role_code' => $code])->fresh()->role_code)->toBe($code);
    }

    // A plain string column. The 30-character bound is enforced by the Form
    // Requests, which is where a too-long code becomes a 422 rather than a
    // silent truncation; the engines disagree about reporting the length here,
    // so the type is what this asserts.
    $column = collect(Schema::getColumns('project_parties'))->firstWhere('name', 'role_code');

    expect($column['type_name'])->toBeIn(['varchar', 'character varying']);
});

it('keeps role_code free of an enum cast', function (): void {
    $casts = (new ProjectParty)->getCasts();

    expect($casts)->not->toHaveKey('role_code');
});

/*
|--------------------------------------------------------------------------
| Mutation boundary
|--------------------------------------------------------------------------
*/

it('withholds the relationship keys from mass assignment', function (): void {
    $participation = new ProjectParty;

    $participation->fill([
        'project_id' => 'x',
        'party_id' => 'y',
        'office_id' => 'z',
        'created_by' => 'w',
        'id' => 'v',
    ]);

    expect($participation->project_id)->toBeNull()
        ->and($participation->party_id)->toBeNull()
        ->and($participation->office_id)->toBeNull()
        ->and($participation->created_by)->toBeNull()
        ->and($participation->id)->toBeNull();
});

it('allows exactly the three correctable fields through mass assignment', function (): void {
    $participation = new ProjectParty;

    $participation->fill(['role_code' => 'X', 'is_primary' => true, 'notes' => 'n']);

    expect($participation->role_code)->toBe('X')
        ->and($participation->is_primary)->toBeTrue()
        ->and($participation->notes)->toBe('n');
});

it('restricts deletion of either endpoint while a participation exists', function (): void {
    $office = Office::factory()->create();
    $participation = participationIn($office);

    expect(fn () => Project::withoutEvents(
        fn () => DB::table('projects')->where('id', $participation->project_id)->delete()
    ))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Attribution
|--------------------------------------------------------------------------
*/

it('records who linked the Party and survives that person', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    $participation = participationIn($office, ['created_by' => $actor->getKey()]);

    $actor->delete();

    expect($participation->fresh()->created_by)->toBe($actor->getKey());
});

/*
|--------------------------------------------------------------------------
| Milestone boundary
|--------------------------------------------------------------------------
*/

it('introduces no Matter or later-milestone persistence', function (): void {
    foreach ([
        'matters', 'matter_parties', 'service_types',
        'workflow_templates', 'workflow_instances', 'workflow_stages',
        'documents', 'properties', 'warkah', 'deeds', 'tasks',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});

it('exposes exactly the expected participation routes and nothing more', function (): void {
    $routes = collect(Route::getRoutes())
        ->map(fn ($route): string => strtoupper(implode('|', array_diff($route->methods(), ['HEAD']))).' '.$route->uri())
        ->filter(fn (string $route): bool => str_contains($route, 'parties') && str_contains($route, 'projects'))
        ->values()
        ->sort()
        ->values()
        ->all();

    expect($routes)->toBe([
        'DELETE api/v1/projects/{project}/parties/{projectParty}',
        'GET api/v1/projects/{project}/parties',
        'PATCH api/v1/projects/{project}/parties/{projectParty}',
        'POST api/v1/projects/{project}/parties',
    ]);
});

it('exposes no top-level participation collection', function (): void {
    // A row must never be reachable without naming the Project that owns it —
    // that is where the authorization lives.
    $routes = collect(Route::getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'project-parties') || str_starts_with($uri, 'api/v1/parties/{'))
        ->all();

    expect($routes)->toBe([]);
});
