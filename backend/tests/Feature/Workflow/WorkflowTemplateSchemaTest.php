<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Models\Office;
use App\Models\ServiceType;
use App\Models\WorkflowStage;
use App\Models\WorkflowTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/

it('creates both workflow tables with the documented columns', function (): void {
    expect(Schema::hasTable('workflow_templates'))->toBeTrue()
        ->and(Schema::hasColumns('workflow_templates', [
            'id', 'office_id', 'service_type_id', 'code', 'name_id', 'name_en',
            'version', 'is_default', 'is_active', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('workflow_stages'))->toBeTrue()
        ->and(Schema::hasColumns('workflow_stages', [
            'id', 'workflow_template_id', 'code', 'name_id', 'name_en', 'sequence_no',
            'target_days', 'requires_approval', 'approval_permission',
            'is_start_stage', 'is_completion_stage', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('adds no column beyond the canonical field lists', function (): void {
    // The ERD section 11 lists exactly these. A column added on speculation is
    // one somebody fills in wrongly (D-095), and a workflow engine is precisely
    // where an invented field would look like a legal rule.
    $templates = Schema::getColumnListing('workflow_templates');
    $stages = Schema::getColumnListing('workflow_stages');

    sort($templates);
    sort($stages);

    expect($templates)->toBe([
        'code', 'created_at', 'id', 'is_active', 'is_default', 'name_en', 'name_id',
        'office_id', 'service_type_id', 'updated_at', 'version',
    ])->and($stages)->toBe([
        'approval_permission', 'code', 'created_at', 'id', 'is_completion_stage',
        'is_start_stage', 'name_en', 'name_id', 'requires_approval', 'sequence_no',
        'target_days', 'updated_at', 'workflow_template_id',
    ]);
});

it('uses is_active for retirement and carries no soft delete', function (): void {
    // The M4.1 position: an inactive template is unavailable for new
    // instantiation and stays readable on every Matter already running it. No
    // `deleted_at` anywhere, so "invisible because retired" can never be confused
    // with "invisible because deleted".
    expect(Schema::hasColumn('workflow_templates', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('workflow_stages', 'deleted_at'))->toBeFalse();
});

it('scopes a template code to its office, never globally', function (): void {
    $officeA = Office::factory()->create();
    $officeB = Office::factory()->create();

    WorkflowTemplate::factory()->for($officeA)->code('STANDARD')->create();

    // Two Offices may both run a `STANDARD`.
    expect(fn () => WorkflowTemplate::factory()->for($officeB)->code('STANDARD')->create())
        ->not->toThrow(QueryException::class);

    expect(fn () => WorkflowTemplate::factory()->for($officeA)->code('STANDARD')->create())
        ->toThrow(QueryException::class);
});

it('refuses a second template row for the same code, whatever its version', function (): void {
    // `version` is a counter on one row, not a second row (D-111). This is the
    // schema half of that decision: there is no way to keep two iterations side
    // by side, because the snapshot in M4.7 is what preserves the old one.
    $office = Office::factory()->create();

    WorkflowTemplate::factory()->for($office)->code('STANDARD')->create();

    expect(fn () => WorkflowTemplate::factory()->for($office)->code('STANDARD')->version(2)->create())
        ->toThrow(QueryException::class);
});

it('carries the support key a later composite foreign key needs', function (): void {
    // Added now rather than by a later ALTER, the M4.1 habit. M4.7's
    // `matter_workflows` is the caller: a Matter must not run another Office's
    // template.
    $office = Office::factory()->create();
    $template = WorkflowTemplate::factory()->for($office)->create();

    expect(fn () => DB::table('workflow_templates')->insert([
        'id' => $template->getKey(),
        'office_id' => $office->getKey(),
        'code' => 'OTHER',
        'name_id' => 'x',
        'name_en' => 'x',
        'version' => 1,
        'is_default' => false,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('scopes a stage code and its position to the template', function (): void {
    $first = WorkflowTemplate::factory()->create();
    $second = WorkflowTemplate::factory()->create();

    WorkflowStage::factory()->for($first, 'template')->atPosition(1)->create();

    // Two templates may both have a stage at position 1 called the same thing.
    expect(fn () => WorkflowStage::factory()->for($second, 'template')->atPosition(1)->create())
        ->not->toThrow(QueryException::class);

    expect(fn () => WorkflowStage::factory()->for($first, 'template')->atPosition(1)->create())
        ->toThrow(QueryException::class);
});

it('refuses two stages at one position even under different codes', function (): void {
    // The engine's own consistency rather than an invented business rule: two
    // stages claiming position 3 leave "what comes next" undefined for the thing
    // whose whole job is answering it.
    $template = WorkflowTemplate::factory()->create();

    WorkflowStage::factory()->for($template, 'template')->atPosition(1)->create();

    expect(fn () => WorkflowStage::factory()->for($template, 'template')
        ->atPosition(1)->code('SOMETHING_ELSE')->create())
        ->toThrow(QueryException::class);
});

it('binds a template to a service type in the same office only', function (): void {
    $office = Office::factory()->create();
    $other = Office::factory()->create();

    $own = ServiceType::factory()->for($office)->create();
    $foreign = ServiceType::factory()->for($other)->create();

    expect(fn () => WorkflowTemplate::factory()->for($office)
        ->state(['service_type_id' => $own->getKey()])->create())
        ->not->toThrow(QueryException::class);

    // Structural, not merely validated: the composite key resolves both
    // endpoints through this table's own `office_id` (D-111).
    expect(fn () => WorkflowTemplate::factory()->for($office)
        ->state(['service_type_id' => $foreign->getKey()])->create())
        ->toThrow(QueryException::class);
});

it('allows a template bound to no service type at all', function (): void {
    // An unbound template is the office's generic process. Requiring a binding
    // would make workflow configuration impossible for as long as the service
    // catalogue is empty — and M4.1 ships it empty on purpose (D-102).
    $template = WorkflowTemplate::factory()->create();

    expect($template->service_type_id)->toBeNull();
});

it('refuses to remove an office or service type still configured', function (): void {
    $office = Office::factory()->create();
    $serviceType = ServiceType::factory()->for($office)->create();

    WorkflowTemplate::factory()->for($office)
        ->state(['service_type_id' => $serviceType->getKey()])->create();

    expect(fn () => DB::table('service_types')->where('id', $serviceType->getKey())->delete())
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('offices')->where('id', $office->getKey())->delete())
        ->toThrow(QueryException::class);
});

it('takes its stages with it when a template is removed', function (): void {
    // The one CASCADE in this schema. A stage has no existence apart from its
    // template, so orphaning stages would leave rows nothing can reach.
    $template = WorkflowTemplate::factory()->withStages(3)->create();

    expect(WorkflowStage::query()->count())->toBe(3);

    DB::table('workflow_templates')->where('id', $template->getKey())->delete();

    expect(WorkflowStage::query()->count())->toBe(0);
});

it('refuses a negative or zero counter on postgresql', function (): void {
    // Laravel's `unsigned*` types are MySQL concepts that PostgreSQL silently
    // maps to signed columns — the M4.1 `default_duration_days` lesson, applied
    // here before it could bite. SQLite cannot add a CHECK after the fact, so
    // this is proven on the engine that actually enforces it.
    $template = WorkflowTemplate::factory()->create();

    expect(fn () => DB::table('workflow_templates')
        ->where('id', $template->getKey())->update(['version' => 0]))
        ->toThrow(QueryException::class);

    $stage = WorkflowStage::factory()->for($template, 'template')->atPosition(1)->create();

    expect(fn () => DB::table('workflow_stages')
        ->where('id', $stage->getKey())->update(['target_days' => -1]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('workflow_stages')
        ->where('id', $stage->getKey())->update(['sequence_no' => 0]))
        ->toThrow(QueryException::class);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'CHECK constraints are PostgreSQL-only here.');

/*
|--------------------------------------------------------------------------
| Model behaviour
|--------------------------------------------------------------------------
*/

it('keeps office and code immutable once a template exists', function (string $attribute): void {
    $template = WorkflowTemplate::factory()->create();

    expect(function () use ($template, $attribute): void {
        $template->{$attribute} = $attribute === 'office_id'
            ? Office::factory()->create()->getKey()
            : 'CHANGED';
        $template->save();
    })->toThrow(RuntimeException::class);
})->with(['office_id', 'code']);

it('lets the version be bumped in place', function (): void {
    // The counterpart to the immutability above, and the reason `version` is not
    // in that set: raising it is the ordinary act of editing a template (D-111).
    $template = WorkflowTemplate::factory()->create();

    $template->version = 2;
    $template->save();

    expect($template->fresh()->version)->toBe(2);
});

it('withholds identity from mass assignment', function (): void {
    $template = WorkflowTemplate::factory()->create();
    $office = Office::factory()->create();

    $template->fill([
        'office_id' => $office->getKey(),
        'code' => 'FILLED',
        'name_id' => 'Diubah',
    ]);

    expect($template->office_id)->not->toBe($office->getKey())
        ->and($template->code)->not->toBe('FILLED')
        ->and($template->name_id)->toBe('Diubah');
});

it('reads its stages in configured order', function (): void {
    $template = WorkflowTemplate::factory()->create();

    foreach ([3, 1, 2] as $position) {
        WorkflowStage::factory()->for($template, 'template')->atPosition($position)->create();
    }

    expect($template->stages()->pluck('sequence_no')->all())->toBe([1, 2, 3]);
});

it('refuses an approval permission that names no canonical code', function (): void {
    // The column stores a permission code as data, which is an authorization
    // surface configured by text. An unregistered value is unresolvable, so
    // storing one would defer to runtime a question with no safe answer (D-111).
    $template = WorkflowTemplate::factory()->create();

    expect(fn () => WorkflowStage::factory()->for($template, 'template')
        ->atPosition(1)
        ->requiringApproval('notary.matters.not_a_real_code')
        ->create())
        ->toThrow(RuntimeException::class);
});

it('accepts a canonical approval permission', function (): void {
    $template = WorkflowTemplate::factory()->create();

    $stage = WorkflowStage::factory()->for($template, 'template')
        ->atPosition(1)
        ->requiringApproval('notary.matters.change_stage')
        ->create();

    expect($stage->approval_permission)->toBe('notary.matters.change_stage')
        ->and($stage->requires_approval)->toBeTrue();
});

it('accepts a stage that requires approval without naming a permission', function (): void {
    // `requires_approval` alone is a meaningful state: the office may know a step
    // needs signing off before it knows which capability should gate it.
    $template = WorkflowTemplate::factory()->create();

    $stage = WorkflowStage::factory()->for($template, 'template')
        ->atPosition(1)->requiringApproval()->create();

    expect($stage->approval_permission)->toBeNull()
        ->and($stage->requires_approval)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

it('adds no permission to the canonical registry', function (): void {
    // Both workflow codes were already canonical, so M4.6 registers nothing. The
    // global total is pinned once, in `PermissionRegistryTest`.
    $workflow = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_contains($code, 'workflow'),
    ));

    sort($workflow);

    expect($workflow)->toBe(['master.workflows.manage', 'master.workflows.view']);
});

it('narrows the workflow permissions to office and all', function (string $code): void {
    $scopes = array_map(
        fn (DataScope $scope): string => $scope->value,
        app(PermissionScopeRules::class)->allowedFor($code),
    );

    expect($scopes)->toBe(['OFFICE', 'ALL']);
})->with(['master.workflows.view', 'master.workflows.manage']);

it('leaves the still-undesigned master families permissive', function (string $code): void {
    // Narrowing a family whose domain does not exist would repeat the mistake the
    // narrowing above corrects, one module across.
    $scopes = array_map(
        fn (DataScope $scope): string => $scope->value,
        app(PermissionScopeRules::class)->allowedFor($code),
    );

    expect($scopes)->toContain('OWN')->and($scopes)->not->toContain('TEAM');
})->with([
    'master.requirements.view', 'master.task_templates.view',
    'master.document_templates.view', 'master.numbering.view', 'master.legal_terms.view',
]);

/*
|--------------------------------------------------------------------------
| Milestone boundary
|--------------------------------------------------------------------------
*/

it('exposes no workflow http surface in m4.6', function (): void {
    // Backend foundation only, following M2.1, M3.1, M4.1 and M4.2. The routes
    // arrive with the milestone that owns them.
    $uris = collect(Route::getRoutes())->map(fn ($route): string => $route->uri());

    foreach (['workflow', 'workflows', 'stages', 'master/workflows'] as $absent) {
        expect($uris->filter(fn (string $uri): bool => str_contains($uri, $absent)))->toBeEmpty($absent);
    }
});

it('builds no matter workflow instance table yet', function (): void {
    // M4.7 owns the running side. Nothing is stubbed ahead of it (D-095).
    foreach (['matter_workflows', 'matter_stage_instances', 'matter_stage_history'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }

    // And `matters` gains no stage pointer here.
    expect(Schema::hasColumn('matters', 'current_stage_id'))->toBeFalse();
});

it('ships both tables empty and seeds no workflow content', function (): void {
    // D-104 in one assertion. A configurable engine with no content is the
    // correct outcome: the office's real workflow is blocked on domain
    // validation, not on engineering.
    expect(WorkflowTemplate::query()->count())->toBe(0)
        ->and(WorkflowStage::query()->count())->toBe(0);
});

it('registers no seeder that would populate a workflow', function (): void {
    $seeders = collect(glob(database_path('seeders/*.php')))
        ->map(fn (string $path): string => basename($path))
        ->values()->all();

    expect($seeders)->toBe(['DatabaseSeeder.php']);

    $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

    foreach (['WorkflowTemplate', 'WorkflowStage', 'workflow_templates', 'workflow_stages'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

it('invents no legal workflow vocabulary in its fixtures', function (): void {
    // A fixture reading `PEMERIKSAAN_BERKAS` or `PENANDATANGANAN` could later be
    // mistaken for validated content — by a reader, by a copy-paste into a
    // seeder, or by somebody reconstructing "how the office works" from the test
    // suite. The factories use `UJI_` deliberately.
    $sources = collect([
        database_path('factories/WorkflowTemplateFactory.php'),
        database_path('factories/WorkflowStageFactory.php'),
    ])->map(fn (string $path): string => strtolower(file_get_contents($path)))->implode("\n");

    // Strip comments before scanning, so the prose explaining the rule does not
    // trip it — the repository's canonical approach to a source scan.
    $stripped = collect(token_get_all('<?php '.$sources))
        ->reject(fn ($token): bool => is_array($token)
            && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($token): string => is_array($token) ? $token[1] : $token)
        ->implode('');

    foreach ([
        'pemeriksaan', 'penandatanganan', 'minuta', 'warkah', 'ajb', 'apht',
        'legalisasi', 'waarmerking', 'repertorium', 'akta',
    ] as $forbidden) {
        expect($stripped)->not->toContain($forbidden);
    }
});

it('makes several defaults representable, because no cardinality rule exists', function (): void {
    // Following `project_parties.is_primary` (D-092) and D-105: no canonical
    // document says exactly one template is the default, so nothing here decides
    // it. **M4.7 must choose deterministically and say how**, rather than
    // assuming the database handed it exactly one.
    $office = Office::factory()->create();

    WorkflowTemplate::factory()->for($office)->default()->create();
    WorkflowTemplate::factory()->for($office)->default()->create();

    expect(WorkflowTemplate::query()->where('is_default', true)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Migration
|--------------------------------------------------------------------------
*/

it('migrates, rolls back, and re-migrates cleanly', function (): void {
    $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

    expect(Schema::hasTable('workflow_templates'))->toBeFalse()
        ->and(Schema::hasTable('workflow_stages'))->toBeFalse()
        // Everything below survives untouched: this migration adds no support
        // key to another table, so it drops none.
        ->and(Schema::hasTable('service_types'))->toBeTrue()
        ->and(Schema::hasTable('matters'))->toBeTrue()
        ->and(Schema::hasTable('matter_parties'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('workflow_templates'))->toBeTrue()
        ->and(Schema::hasTable('workflow_stages'))->toBeTrue();
});

it('generates ulid keys for both tables', function (): void {
    $template = WorkflowTemplate::factory()->withStages(2)->create();

    expect(Str::isUlid($template->getKey()))->toBeTrue()
        ->and(Str::isUlid($template->stages()->first()->getKey()))->toBeTrue();
});
