<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Matter\Actions\CreateMatter;
use App\Domains\Matter\Enums\MatterDomain;
use App\Models\Matter;
use App\Models\MatterStageHistory;
use App\Models\MatterStageInstance;
use App\Models\MatterWorkflow;
use App\Models\Office;
use App\Models\Project;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function stageActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/**
 * A template with a run of stages, in the given Office.
 */
function stageTemplate(Office $office, int $stages = 3, ?ServiceType $serviceType = null): WorkflowTemplate
{
    $template = WorkflowTemplate::factory()->for($office)->default()->create([
        'service_type_id' => $serviceType?->getKey(),
    ]);

    foreach (range(1, $stages) as $position) {
        WorkflowStage::factory()->for($template, 'template')->atPosition($position)->create();
    }

    return $template;
}

function stageMatter(
    User $actor,
    Office $office,
    MatterDomain $domain = MatterDomain::NOTARY,
    ?ServiceType $serviceType = null,
): Matter {
    $project = Project::factory()->for($office)->create();

    return app(CreateMatter::class)->handle(
        $actor,
        $project,
        $domain,
        $serviceType?->getKey(),
        ['title' => 'Pekerjaan Uji'],
    );
}

/**
 * @return array<int, string>
 */
function stageCapabilities(): array
{
    return [
        'projects.view', 'notary.matters.view', 'notary.matters.create',
        'notary.matters.change_stage', 'notary.matters.complete',
    ];
}

/*
|--------------------------------------------------------------------------
| Routes and permissions
|--------------------------------------------------------------------------
*/

it('registers exactly the expected stage routes and nothing more', function (): void {
    $routes = collect(Route::getRoutes())
        ->map(fn ($route): string => strtoupper(implode('|', array_diff($route->methods(), ['HEAD']))).' '.$route->uri())
        ->filter(fn (string $route): bool => str_contains($route, 'stages'))
        ->values()->sort()->values()->all();

    expect($routes)->toBe([
        'GET api/v1/notary/matters/{matter}/stages',
        'GET api/v1/notary/matters/{matter}/stages/options',
        'GET api/v1/ppat/matters/{matter}/stages',
        'GET api/v1/ppat/matters/{matter}/stages/options',
        'POST api/v1/notary/matters/{matter}/stages/move',
        'POST api/v1/ppat/matters/{matter}/stages/move',
    ]);
});

it('adds no permission and keeps the count at 177', function (): void {
    // `*.matters.change_stage` has been canonical since the catalogue was
    // transcribed. M4.7 gives it a route; it registers nothing.
    expect(PermissionRegistry::count())->toBe(177)
        ->and(PermissionRegistry::all())->toContain('notary.matters.change_stage')
        ->and(PermissionRegistry::all())->toContain('ppat.matters.change_stage');
});

it('no longer badges change_stage as deferred', function (): void {
    // A badge that outlives its reason trains people to ignore the badge (D-077).
    [$actor] = stageActor(['permissions.view'], DataScope::ALL);

    $response = $this->actingAs($actor)->getJson('/api/v1/permissions')->assertOk();

    expect($response->json('meta.deferred'))
        ->not->toContain('notary.matters.change_stage')
        ->not->toContain('ppat.matters.change_stage');
});

it('invents no stage permission of its own', function (): void {
    $stageCodes = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_contains($code, 'stage'),
    ));

    sort($stageCodes);

    expect($stageCodes)->toBe(['notary.matters.change_stage', 'ppat.matters.change_stage']);
});

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/

it('creates the three running-workflow tables', function (): void {
    expect(Schema::hasColumns('matter_workflows', [
        'id', 'matter_id', 'workflow_template_id', 'workflow_version',
        'started_at', 'completed_at', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('matter_stage_instances', [
            'id', 'matter_workflow_id', 'workflow_stage_id', 'stage_code',
            'stage_name_snapshot_id', 'stage_name_snapshot_en', 'sequence_no', 'status',
            'started_at', 'completed_at', 'assigned_user_id', 'approved_at', 'approved_by',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('matter_stage_history', [
            'id', 'matter_id', 'from_stage_code', 'to_stage_code',
            'changed_by', 'reason', 'changed_at',
        ]))->toBeTrue();
});

it('keeps history append-only in the schema', function (): void {
    // No `updated_at` to bump and no `deleted_at` to set, matching a table
    // nothing may edit (D-104, CLAUDE.md section 31).
    expect(Schema::hasColumn('matter_stage_history', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('matter_stage_history', 'deleted_at'))->toBeFalse();
});

it('adds no current stage pointer to matters', function (): void {
    // The ACTIVE stage instance *is* the current stage. A denormalized pointer
    // would be a second source of truth that can disagree with it, and
    // correcting one without the other would be silent corruption (D-112).
    expect(Schema::hasColumn('matters', 'current_stage_id'))->toBeFalse()
        ->and(Schema::hasColumn('matters', 'workflow_template_id'))->toBeFalse();
});

it('allows only one workflow per matter', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    expect(fn () => MatterWorkflow::factory()->create([
        'matter_id' => $matter->getKey(),
        'workflow_template_id' => WorkflowTemplate::factory()->for($office)->create()->getKey(),
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| The RESTRICT that protects the snapshot
|--------------------------------------------------------------------------
*/

it('refuses to delete a template stage that a matter is running', function (): void {
    // **The load-bearing constraint of this milestone.** M4.6's stages cascade
    // from their template, so a CASCADE here would chain: deleting a template
    // would delete its stages, which would delete running Matters' instances,
    // destroying the history snapshotting exists to preserve (D-112).
    [$actor, $office] = stageActor(stageCapabilities());
    $template = stageTemplate($office);
    stageMatter($actor, $office);

    $stageId = $template->stages()->first()->getKey();

    expect(fn () => DB::table('workflow_stages')->where('id', $stageId)->delete())
        ->toThrow(QueryException::class);
});

it('refuses to delete a template a matter is running, which stops the cascade at its source', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    $template = stageTemplate($office);
    stageMatter($actor, $office);

    expect(fn () => DB::table('workflow_templates')->where('id', $template->getKey())->delete())
        ->toThrow(QueryException::class);

    // Nothing was taken with it.
    expect(MatterStageInstance::query()->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Instantiation
|--------------------------------------------------------------------------
*/

it('instantiates a workflow when a matter is created', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    $template = stageTemplate($office);

    $matter = stageMatter($actor, $office);

    $workflow = MatterWorkflow::query()->where('matter_id', $matter->getKey())->firstOrFail();

    expect($workflow->workflow_template_id)->toBe($template->getKey())
        ->and($workflow->workflow_version)->toBe($template->version)
        ->and($workflow->started_at)->not->toBeNull()
        ->and($workflow->completed_at)->toBeNull()
        ->and($workflow->stages()->count())->toBe(3);
});

it('creates a matter without a workflow when no template is configured', function (): void {
    // **The ordinary path**, not an edge case: D-104 seeds no templates, so a
    // fresh deployment has none until an office enters some. Failing Matter
    // creation would make the whole module depend on domain validation that has
    // not happened.
    [$actor, $office] = stageActor(stageCapabilities());

    $matter = stageMatter($actor, $office);

    expect($matter->exists)->toBeTrue()
        ->and(MatterWorkflow::query()->where('matter_id', $matter->getKey())->exists())->toBeFalse();
});

it('opens the first stage and leaves the rest pending', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $stages = MatterWorkflow::query()->where('matter_id', $matter->getKey())
        ->firstOrFail()->stages()->get();

    expect($stages->pluck('status')->map(fn ($s): string => $s->value)->all())
        ->toBe(['ACTIVE', 'PENDING', 'PENDING'])
        ->and($stages->first()->started_at)->not->toBeNull()
        ->and($stages->last()->started_at)->toBeNull();
});

it('records the opening transition with no origin', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $history = MatterStageHistory::query()->where('matter_id', $matter->getKey())->get();

    expect($history)->toHaveCount(1)
        ->and($history->first()->from_stage_code)->toBeNull()
        ->and($history->first()->to_stage_code)->toBe('UJI_TAHAP_1')
        ->and($history->first()->changed_by)->toBe($actor->getKey());
});

it('snapshots the stage names rather than referencing them', function (): void {
    // The requirement of CLAUDE.md section 18: editing a template must not
    // retroactively change a Matter already running.
    [$actor, $office] = stageActor(stageCapabilities());
    $template = stageTemplate($office, 2);
    $matter = stageMatter($actor, $office);

    $template->stages()->first()->update([
        'name_id' => 'Nama Baru',
        'name_en' => 'Renamed',
    ]);

    $instance = MatterWorkflow::query()->where('matter_id', $matter->getKey())
        ->firstOrFail()->stages()->first();

    expect($instance->stage_name_snapshot_id)->toBe('Tahap Uji 1')
        ->and($instance->stage_name_snapshot_en)->toBe('Test Stage 1');
});

it('holds a name in the snapshot column, not a ulid', function (): void {
    // `stage_name_snapshot_id` is not a foreign key: `_id` is the locale code for
    // Bahasa Indonesia. Every other `*_id` column in this domain does hold a
    // reference, so the name genuinely invites a wrong join.
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office, 1);
    $matter = stageMatter($actor, $office);

    $instance = MatterWorkflow::query()->where('matter_id', $matter->getKey())
        ->firstOrFail()->stages()->first();

    expect(Str::isUlid($instance->stage_name_snapshot_id))->toBeFalse()
        ->and($instance->stage_name_snapshot_id)->toBe('Tahap Uji 1');
});

it('prefers a template bound to the matter service type', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    $serviceType = ServiceType::factory()->for($office)->create();

    $generic = stageTemplate($office, 2);
    $specific = stageTemplate($office, 4, $serviceType);

    $matter = stageMatter($actor, $office, MatterDomain::NOTARY, $serviceType);

    $workflow = MatterWorkflow::query()->where('matter_id', $matter->getKey())->firstOrFail();

    expect($workflow->workflow_template_id)->toBe($specific->getKey())
        ->and($workflow->workflow_template_id)->not->toBe($generic->getKey());
});

it('falls back to the generic template when the service type has none', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    $serviceType = ServiceType::factory()->for($office)->create();
    $generic = stageTemplate($office, 2);

    $matter = stageMatter($actor, $office, MatterDomain::NOTARY, $serviceType);

    expect(MatterWorkflow::query()->where('matter_id', $matter->getKey())->firstOrFail()
        ->workflow_template_id)->toBe($generic->getKey());
});

it('breaks a tie between several defaults deterministically', function (): void {
    // M4.6 put no uniqueness on `is_default` (D-111), so this action must choose
    // and say how: `is_default` first, then the oldest by ULID — the established
    // default rather than one created this morning.
    [$actor, $office] = stageActor(stageCapabilities());

    $first = stageTemplate($office, 2);
    $second = stageTemplate($office, 5);

    $matter = stageMatter($actor, $office);

    $workflow = MatterWorkflow::query()->where('matter_id', $matter->getKey())->firstOrFail();

    $oldest = collect([$first, $second])->sortBy(fn (WorkflowTemplate $t): string => $t->getKey())->first();

    expect($workflow->workflow_template_id)->toBe($oldest->getKey());
});

it('prefers a default template over a non-default one', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());

    // Created first, so the id tie-break would pick it if `is_default` did not
    // come first.
    $plain = WorkflowTemplate::factory()->for($office)->create();
    WorkflowStage::factory()->for($plain, 'template')->atPosition(1)->create();

    $default = stageTemplate($office, 2);

    $matter = stageMatter($actor, $office);

    expect(MatterWorkflow::query()->where('matter_id', $matter->getKey())->firstOrFail()
        ->workflow_template_id)->toBe($default->getKey());
});

it('ignores a retired template', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    $retired = stageTemplate($office, 2);
    $retired->update(['is_active' => false]);

    $matter = stageMatter($actor, $office);

    expect(MatterWorkflow::query()->where('matter_id', $matter->getKey())->exists())->toBeFalse();
});

it('ignores another office template', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate(Office::factory()->create(), 3);

    $matter = stageMatter($actor, $office);

    expect(MatterWorkflow::query()->where('matter_id', $matter->getKey())->exists())->toBeFalse();
});

it('rolls the workflow back with the matter when creation fails', function (): void {
    // The instantiation joins the caller's transaction: a workflow that committed
    // while its Matter rolled back would be an orphan the UNIQUE (matter_id) key
    // then blocks forever.
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $project = Project::factory()->for($office)->create();

    try {
        DB::transaction(function () use ($actor, $project): void {
            app(CreateMatter::class)
                ->handle($actor, $project, MatterDomain::NOTARY, null, ['title' => 'Uji']);

            throw new RuntimeException('deliberate');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Matter::query()->count())->toBe(0)
        ->and(MatterWorkflow::query()->count())->toBe(0)
        ->and(MatterStageInstance::query()->count())->toBe(0)
        ->and(MatterStageHistory::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

it('reads the workflow through the matter view capability', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/stages")->assertOk();

    expect($response->json('meta.has_workflow'))->toBeTrue()
        ->and($response->json('data.current_stage.stage_code'))->toBe('UJI_TAHAP_1')
        ->and($response->json('data.stages'))->toHaveCount(3)
        ->and($response->json('data.history'))->toHaveCount(1)
        ->and($response->json('data.workflow.workflow_version'))->toBe(1);
});

it('answers 200 with an empty workflow when none was instantiated', function (): void {
    // Not 404: the Matter exists and genuinely has no process configured, and the
    // interface needs to say so rather than look broken.
    [$actor, $office] = stageActor(stageCapabilities());
    $matter = stageMatter($actor, $office);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/stages")->assertOk();

    expect($response->json('meta.has_workflow'))->toBeFalse()
        ->and($response->json('data.workflow'))->toBeNull()
        ->and($response->json('data.stages'))->toBe([]);
});

it('refuses the workflow read without matter view', function (): void {
    [$owner, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($owner, $office);

    [$stranger] = stageActor(['notary.matters.change_stage']);

    $this->actingAs($stranger)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/stages")->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Moving
|--------------------------------------------------------------------------
*/

it('moves to another stage and completes the one it left', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
            'target_stage_code' => 'UJI_TAHAP_2',
            'reason' => 'Berkas lengkap',
        ])->assertOk()->assertJsonPath('data.stage_code', 'UJI_TAHAP_2');

    $stages = MatterWorkflow::query()->where('matter_id', $matter->getKey())
        ->firstOrFail()->stages()->get();

    expect($stages->pluck('status')->map(fn ($s): string => $s->value)->all())
        ->toBe(['COMPLETED', 'ACTIVE', 'PENDING'])
        ->and($stages->first()->completed_at)->not->toBeNull();
});

it('leaves jumped stages pending rather than marking them skipped', function (): void {
    // Skipping is a decision somebody makes; moving to a later stage is not that
    // decision (D-112). SKIPPED stays vocabulary nothing sets.
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
            'target_stage_code' => 'UJI_TAHAP_3',
        ])->assertOk();

    $stages = MatterWorkflow::query()->where('matter_id', $matter->getKey())
        ->firstOrFail()->stages()->get();

    expect($stages->pluck('status')->map(fn ($s): string => $s->value)->all())
        ->toBe(['COMPLETED', 'PENDING', 'ACTIVE']);
});

it('never sets skipped or blocked anywhere in the product', function (): void {
    // The gap recorded rather than filled by inference: no code path writes
    // either status, and the source scan says so once rather than per call site.
    //
    // **Scanned per file, and only where the string appears at all.**
    // Concatenating `app/` and tokenizing it in one pass exhausted PHP's 128MB
    // limit outright — the repository's comment-stripping idiom does not scale to
    // a whole directory. Tokenizing the handful of files that mention the enum is
    // the same check for a fraction of the memory.
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getRealPath());

        if (! str_contains($source, 'MatterStageStatus::')) {
            continue;
        }

        $stripped = collect(token_get_all($source))
            ->reject(fn ($token): bool => is_array($token)
                && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($token): string => is_array($token) ? $token[1] : $token)
            ->implode('');

        foreach (['MatterStageStatus::SKIPPED', 'MatterStageStatus::BLOCKED'] as $forbidden) {
            if (str_contains($stripped, $forbidden)) {
                $offenders[] = $file->getRelativePathname().' → '.$forbidden;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('records every move in append-only history', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
            'target_stage_code' => 'UJI_TAHAP_2',
            'reason' => 'Berkas lengkap',
        ])->assertOk();

    $history = MatterStageHistory::query()->where('matter_id', $matter->getKey())
        ->orderBy('changed_at')->orderBy('id')->get();

    expect($history)->toHaveCount(2)
        ->and($history->last()->from_stage_code)->toBe('UJI_TAHAP_1')
        ->and($history->last()->to_stage_code)->toBe('UJI_TAHAP_2')
        ->and($history->last()->reason)->toBe('Berkas lengkap');
});

it('refuses to edit or delete a history record', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $entry = MatterStageHistory::query()->where('matter_id', $matter->getKey())->firstOrFail();

    expect(function () use ($entry): void {
        $entry->reason = 'rewritten';
        $entry->save();
    })->toThrow(RuntimeException::class);

    expect(fn () => $entry->delete())->toThrow(RuntimeException::class);
});

it('refuses a stage that is not in this matter workflow', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
            'target_stage_code' => 'TIDAK_ADA',
        ])->assertStatus(422);
});

it('refuses a stage that is already completed', function (): void {
    // Not a transition rule: a destination must be somewhere you can go (D-104).
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
        'target_stage_code' => 'UJI_TAHAP_2',
    ])->assertOk();

    $this->actingAs($actor)->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
        'target_stage_code' => 'UJI_TAHAP_1',
    ])->assertStatus(422);
});

it('refuses moving to the stage already active', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
            'target_stage_code' => 'UJI_TAHAP_1',
        ])->assertStatus(422);
});

it('allows moving backwards, because there is no transition matrix', function (): void {
    // M4 authorizes who may change a stage and never encodes which stage may
    // follow which (D-104). A backward move is ordinary.
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
        'target_stage_code' => 'UJI_TAHAP_3',
    ])->assertOk();

    // Stage 2 was never entered, so it is still open to move to.
    $this->actingAs($actor)->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
        'target_stage_code' => 'UJI_TAHAP_2',
    ])->assertOk();

    $stages = MatterWorkflow::query()->where('matter_id', $matter->getKey())
        ->firstOrFail()->stages()->get();

    expect($stages->pluck('status')->map(fn ($s): string => $s->value)->all())
        ->toBe(['COMPLETED', 'ACTIVE', 'COMPLETED']);
});

it('refuses a move on a matter with no workflow', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
            'target_stage_code' => 'ANY',
        ])->assertStatus(422);
});

it('never touches matter status when a stage moves', function (): void {
    // Matter Status and Workflow Stage are separate concepts and must not be
    // merged (CLAUDE.md section 18, D-104).
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
        'target_stage_code' => 'UJI_TAHAP_2',
    ])->assertOk();

    expect($matter->fresh()->status->value)->toBe('OPEN');
});

it('refuses every system-controlled field on a move', function (string $field): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
            'target_stage_code' => 'UJI_TAHAP_2',
            $field => 'anything',
        ])->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with([
    'matter_id', 'domain', 'status', 'sequence_no', 'started_at', 'completed_at',
    'assigned_user_id', 'approved_at', 'approved_by', 'changed_by', 'changed_at',
]);

it('requires a target stage code', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [])
        ->assertStatus(422)->assertJsonValidationErrors(['target_stage_code']);
});

/*
|--------------------------------------------------------------------------
| Options
|--------------------------------------------------------------------------
*/

it('offers the open stages except the current one', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $codes = collect(
        $this->actingAs($actor)
            ->getJson("/api/v1/notary/matters/{$matter->getKey()}/stages/options")
            ->assertOk()->json('data.stages')
    )->pluck('stage_code')->all();

    expect($codes)->toBe(['UJI_TAHAP_2', 'UJI_TAHAP_3']);
});

it('drops a completed stage from the options', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
        'target_stage_code' => 'UJI_TAHAP_2',
    ])->assertOk();

    $codes = collect(
        $this->actingAs($actor)
            ->getJson("/api/v1/notary/matters/{$matter->getKey()}/stages/options")
            ->assertOk()->json('data.stages')
    )->pluck('stage_code')->all();

    expect($codes)->toBe(['UJI_TAHAP_3']);
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('refuses a move without change_stage', function (): void {
    [$actor, $office] = stageActor([
        'projects.view', 'notary.matters.view', 'notary.matters.create', 'notary.matters.update',
    ]);
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/stages/move", [
            'target_stage_code' => 'UJI_TAHAP_2',
        ])->assertForbidden();

    $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/stages/options")
        ->assertForbidden();
});

it('reports the change_stage capability on the matter', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)->getJson("/api/v1/notary/matters/{$matter->getKey()}")
        ->assertOk()->assertJsonPath('data.can_change_stage', true);
});

it('answers 404 for a matter of the other domain', function (): void {
    [$actor, $office] = stageActor(array_merge(stageCapabilities(), [
        'ppat.matters.view', 'ppat.matters.change_stage',
    ]));
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)
        ->getJson("/api/v1/ppat/matters/{$matter->getKey()}/stages")->assertNotFound();

    $this->actingAs($actor)
        ->postJson("/api/v1/ppat/matters/{$matter->getKey()}/stages/move", [
            'target_stage_code' => 'UJI_TAHAP_2',
        ])->assertNotFound();
});

it('applies the matter data scope to stage movement', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach (['projects.view', 'notary.matters.view', 'notary.matters.create'] as $code) {
        grantPermissionScope($actor, $code, DataScope::OFFICE);
    }

    grantPermissionScope($actor, 'notary.matters.change_stage', DataScope::ASSIGNED);

    $actor = $actor->fresh();
    stageTemplate($office);

    $matter = stageMatter($actor, $office);

    // Not the PIC: ASSIGNED does not reach it.
    $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/stages/options")->assertForbidden();

    $matter->forceFill(['pic_user_id' => $actor->getKey()])->save();

    $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/stages/options")->assertOk();
});

it('does not let a stage assignee widen the matter assigned scope', function (): void {
    // D-100: a stage assignee is operational information, never an authorization
    // predicate. Matter ASSIGNED means `matters.pic_user_id` and nothing else.
    $office = Office::factory()->create();
    $owner = User::factory()->for($office)->create();
    $assignee = User::factory()->for($office)->create();

    foreach (['projects.view', 'notary.matters.view', 'notary.matters.create'] as $code) {
        grantPermissionScope($owner, $code, DataScope::OFFICE);
    }

    grantPermissionScope($assignee, 'notary.matters.view', DataScope::ASSIGNED);

    stageTemplate($office);
    $matter = stageMatter($owner->fresh(), $office);

    // Assigned to the *stage*, not the Matter.
    MatterWorkflow::query()->where('matter_id', $matter->getKey())->firstOrFail()
        ->stages()->first()->forceFill(['assigned_user_id' => $assignee->getKey()])->save();

    $this->actingAs($assignee->fresh())
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}")->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Completion
|--------------------------------------------------------------------------
*/

it('closes the workflow when the matter is completed', function (): void {
    // A stage completes by moving on from it, so the final stage would never
    // complete on its own and completed_at would be unreachable schema (D-112).
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/complete")->assertOk();

    $workflow = MatterWorkflow::query()->where('matter_id', $matter->getKey())->firstOrFail();

    expect($workflow->completed_at)->not->toBeNull()
        ->and($workflow->stages()->where('status', 'ACTIVE')->count())->toBe(0)
        ->and($workflow->stages()->first()->status->value)->toBe('COMPLETED');
});

it('writes no history row when completing the matter', function (): void {
    // History records transitions, and completing is not one — nothing moves
    // anywhere. A row whose from and to were the same stage would put a movement
    // in the record that never happened.
    [$actor, $office] = stageActor(stageCapabilities());
    stageTemplate($office);
    $matter = stageMatter($actor, $office);

    $before = MatterStageHistory::query()->where('matter_id', $matter->getKey())->count();

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/complete")->assertOk();

    expect(MatterStageHistory::query()->where('matter_id', $matter->getKey())->count())->toBe($before);
});

it('completes a matter that has no workflow exactly as before', function (): void {
    [$actor, $office] = stageActor(stageCapabilities());
    $matter = stageMatter($actor, $office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/complete")
        ->assertOk()->assertJsonPath('data.status', 'COMPLETED');
});

/*
|--------------------------------------------------------------------------
| Milestone boundary
|--------------------------------------------------------------------------
*/

it('ships no workflow content', function (): void {
    // D-104, still. M4.7 runs workflows; it authors none.
    expect(WorkflowTemplate::query()->count())->toBe(0)
        ->and(WorkflowStage::query()->count())->toBe(0);
});

it('exposes no stage assignment or approval surface', function (): void {
    $uris = collect(Route::getRoutes())->map(fn ($route): string => $route->uri());

    foreach (['stages/assign', 'stages/approve', 'stage-instances', 'stage-history'] as $absent) {
        expect($uris->filter(fn (string $uri): bool => str_contains($uri, $absent)))->toBeEmpty($absent);
    }
});

it('migrates, rolls back, and re-migrates cleanly', function (): void {
    $steps = rollbackStepsTo('create_matter_workflow_tables');

    $this->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();

    expect(Schema::hasTable('matter_workflows'))->toBeFalse()
        ->and(Schema::hasTable('matter_stage_instances'))->toBeFalse()
        ->and(Schema::hasTable('matter_stage_history'))->toBeFalse()
        // M4.6 survives untouched.
        ->and(Schema::hasTable('workflow_templates'))->toBeTrue()
        ->and(Schema::hasTable('workflow_stages'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('matter_workflows'))->toBeTrue();
});
