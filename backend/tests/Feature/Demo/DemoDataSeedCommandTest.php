<?php

use App\Domains\Authorization\DefaultRoleRegistry;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\SyncCanonicalPermissions;
use App\Domains\Demo\DemoDataSeeder;
use App\Domains\Demo\Exceptions\DemoDatasetAlreadyExists;
use App\Domains\Demo\Exceptions\DemoRolePrerequisiteMissing;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Task\Actions\CreateTask;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Individual;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\IndividualPolicy;
use App\Policies\MatterPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * `phpunit.xml` fixes APP_ENV=testing and a SQLite `:memory:` connection for
 * every Feature test (`phpunit.xml:40,45-46`) — neither of those is ever
 * `local` plus a connection literally named `notary_ppat_demo`, so
 * `DemoEnvironmentGuard::assertSafe()` refuses in every test in this file by
 * construction, without this suite doing anything to force it. That is
 * exactly what the guard boundary tests below rely on, and exactly why a
 * genuine `notary_ppat_demo`-named-connection integration test is not
 * attempted here — DemoEnvironmentGuardTest (Task 1) already proves the
 * guard's full accept/reject matrix against a hand-written connection fake.
 * This file proves two different things: that the command actually calls
 * that guard before anything else, and that the orchestration behind it is
 * correct once a caller (never this suite, via the command) gets past it.
 *
 * One consequence of the guard rejecting unconditionally in this environment:
 * this suite can prove `DemoDataSeedCommand` maps `DemoDatasetAlreadyExists`
 * to a non-zero exit (the fix this file's rerun-idempotency tests were
 * revised for) only at the point the guard cannot intercept — calling
 * `DemoDataSeeder::seed()` directly, the same way the marker-ordering test
 * below already had to. There is no way, in this suite, to also observe that
 * mapping *through* `demo:seed` on a second real run: reaching the seeder at
 * all requires clearing the guard first, and nothing here is allowed to fake
 * being `local` against a database named exactly `notary_ppat_demo` to do it.
 */
beforeEach(function (): void {
    expect(DB::connection()->getDatabaseName())->not->toBe('notary_ppat_office');
});

/**
 * Grants `SUPER_ADMIN` every canonical permission at `ALL` scope — the exact
 * shape `BootstrapDeploymentCommand::grantEverything()` produces on a real
 * deployment (app/Console/Commands/BootstrapDeploymentCommand.php) — without
 * running `permissions:sync` or `app:bootstrap` as a side-effecting command
 * against any persistent database. `SyncCanonicalPermissions::handle()` is
 * the same in-process service `app:bootstrap` calls internally; calling it
 * here, against this test's own throwaway SQLite `:memory:` connection, is
 * no different from any other test in this codebase fabricating its own
 * fixture state.
 */
function bootstrapLoginReadyRole(): Role
{
    app(SyncCanonicalPermissions::class)->handle();

    $role = Role::create(['name' => DefaultRoleRegistry::ADMINISTRATOR, 'guard_name' => PermissionRegistry::GUARD]);

    $permissions = Permission::query()
        ->where('guard_name', PermissionRegistry::GUARD)
        ->whereIn('name', PermissionRegistry::all())
        ->get();

    $role->syncPermissions($permissions);

    $now = now();

    DB::table('role_permission_scopes')->insert(
        $permissions->map(fn (Permission $permission): array => [
            'id' => (string) Str::ulid(),
            'role_id' => $role->getKey(),
            'permission_id' => $permission->getKey(),
            'scope' => DataScope::ALL->value,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all()
    );

    return $role;
}

/**
 * @return array<string, int>
 */
function demoEntityCounts(): array
{
    return [
        'organizations' => Organization::query()->count(),
        'offices' => Office::query()->count(),
        'users' => User::query()->count(),
        'parties' => Party::query()->count(),
        'individuals' => Individual::query()->count(),
        'companies' => Company::query()->count(),
        'projects' => Project::query()->count(),
        'matters' => Matter::query()->count(),
        'documents' => Document::query()->count(),
        'tasks' => Task::query()->count(),
    ];
}

describe('DemoDataSeedCommand — guard boundary', function () {
    it('refuses before any write when the environment is not local', function () {
        expect(app()->environment())->not->toBe('local');

        $this->artisan('demo:seed')->assertExitCode(Command::FAILURE);

        expect(Organization::query()->count())->toBe(0)
            ->and(Office::query()->count())->toBe(0);
    });

    it('checks the guard before the idempotency marker, not after', function () {
        // Simulate "the dataset already exists" by planting the marker
        // directly — if the command checked the marker before the guard, this
        // would report DemoDatasetAlreadyExists's message instead of the
        // guard's, and would do so without the guard ever running.
        $organization = new Organization;
        $organization->name = 'Sudah Ada';
        $organization->save();

        $office = new Office;
        $office->organization_id = $organization->getKey();
        $office->code = DemoDataSeeder::OFFICE_CODE;
        $office->name = 'Sudah Ada';
        $office->save();

        $this->artisan('demo:seed')
            ->assertExitCode(Command::FAILURE)
            ->expectsOutputToContain('Demo tooling only runs in that environment.');
    });
});

describe('DemoDataSeeder — orchestration', function () {
    beforeEach(function () {
        // Every test in this block calls the seeder directly, never through
        // the command — the guard is deliberately not exercised here (it has
        // its own full test suite from Task 1). Both disks are faked so
        // nothing this suite runs ever writes a real file anywhere on disk.
        Storage::fake('local_demo');
        Storage::fake('local');

        // The seeder now refuses to run at all unless SUPER_ADMIN already
        // holds a usable grant for every surface its primary actor needs
        // (see DemoRolePrerequisiteMissing — a separate describe block below
        // covers what happens without this).
        bootstrapLoginReadyRole();
    });

    it('creates the minimum dataset on a first run', function () {
        $result = app(DemoDataSeeder::class)->seed();

        expect($result->officeCode)->toBe(DemoDataSeeder::OFFICE_CODE)
            ->and($result->users)->toBe(5)
            ->and($result->parties)->toBe(9)
            ->and($result->projects)->toBe(3)
            ->and($result->matters)->toBe(3)
            ->and($result->documents)->toBe(6)
            ->and($result->tasks)->toBe(6);

        expect(Organization::query()->count())->toBe(1)
            ->and(Office::query()->count())->toBe(1)
            ->and(User::query()->count())->toBe(5)
            ->and(Party::query()->count())->toBe(9)
            ->and(Individual::query()->count())->toBe(6)
            ->and(Company::query()->count())->toBe(3)
            ->and(Project::query()->count())->toBe(3)
            ->and(Matter::query()->count())->toBe(3)
            ->and(Document::query()->count())->toBe(6)
            ->and(Task::query()->count())->toBe(6);
    });

    it('refuses a second run, throwing before any write, and changes nothing', function () {
        app(DemoDataSeeder::class)->seed();

        $before = demoEntityCounts();

        expect(fn () => app(DemoDataSeeder::class)->seed())
            ->toThrow(DemoDatasetAlreadyExists::class);

        expect(demoEntityCounts())->toBe($before);
    });

    it('leaves no partial dataset, and no orphaned demo files, when orchestration fails midway', function () {
        // CreateTask is the last kind of record the seeder writes, so
        // failing it exercises the full chain of prior work being rolled
        // back — Projects, Matters and Documents included.
        $this->mock(CreateTask::class, function ($mock) {
            $mock->shouldReceive('handle')->andThrow(new RuntimeException('simulated mid-run failure'));
        });

        expect(fn () => app(DemoDataSeeder::class)->seed())->toThrow(RuntimeException::class);

        expect(Organization::query()->count())->toBe(0)
            ->and(Office::query()->count())->toBe(0)
            ->and(User::query()->count())->toBe(0)
            ->and(Party::query()->count())->toBe(0)
            ->and(Project::query()->count())->toBe(0)
            ->and(Matter::query()->count())->toBe(0)
            ->and(Document::query()->count())->toBe(0)
            // The six documents already uploaded before CreateTask ran had
            // their rows rolled back by the transaction; the cleanup path
            // must also have cleared the disk those rows pointed at.
            ->and(Storage::disk('local_demo')->allFiles())->toBe([]);
    });

    it('never sets nik, npwp, or tax_id on any created party', function () {
        app(DemoDataSeeder::class)->seed();

        expect(Individual::query()->whereNotNull('nik')->count())->toBe(0)
            ->and(Individual::query()->whereNotNull('npwp')->count())->toBe(0)
            ->and(Company::query()->whereNotNull('tax_id')->count())->toBe(0);
    });

    it('allocates project, matter, and document numbers rather than hardcoding them', function () {
        app(DemoDataSeeder::class)->seed();

        $projectNumbers = Project::query()->pluck('project_number');
        $matterNumbers = Matter::query()->pluck('matter_number');
        $documentNumbers = Document::query()->pluck('document_number');

        foreach ($projectNumbers as $number) {
            expect($number)->toMatch('/^PRJ-\d{4}-\d{6}$/');
        }

        foreach ($matterNumbers as $number) {
            expect($number)->toMatch('/^[NP]-\d{4}-\d{6}$/');
        }

        foreach ($documentNumbers as $number) {
            expect($number)->toMatch('/^DOC-\d{4}-\d{6}$/');
        }

        // Allocated, not hardcoded, also means every one is distinct.
        expect($projectNumbers->unique())->toHaveCount($projectNumbers->count())
            ->and($matterNumbers->unique())->toHaveCount($matterNumbers->count())
            ->and($documentNumbers->unique())->toHaveCount($documentNumbers->count());
    });

    it('keeps every relation valid and inside the demo office', function () {
        app(DemoDataSeeder::class)->seed();

        $office = Office::query()->where('code', DemoDataSeeder::OFFICE_CODE)->firstOrFail();

        expect(User::query()->where('office_id', '!=', $office->getKey())->count())->toBe(0)
            ->and(Party::query()->where('office_id', '!=', $office->getKey())->count())->toBe(0)
            ->and(Project::query()->where('office_id', '!=', $office->getKey())->count())->toBe(0)
            ->and(Matter::query()->where('office_id', '!=', $office->getKey())->count())->toBe(0)
            ->and(Document::query()->where('office_id', '!=', $office->getKey())->count())->toBe(0)
            ->and(Task::query()->where('office_id', '!=', $office->getKey())->count())->toBe(0);

        $projectIds = Project::query()->pluck('id');

        foreach (Matter::query()->pluck('project_id') as $projectId) {
            expect($projectIds)->toContain($projectId);
        }
    });

    it('only reaches matter, document, and task statuses achievable through real lifecycle actions', function () {
        app(DemoDataSeeder::class)->seed();

        $matterStatuses = Matter::query()->pluck('status')->map(fn ($s) => $s->value)->unique()->sort()->values();
        $documentStatuses = Document::query()->pluck('status')->map(fn ($s) => $s->value)->unique()->sort()->values();
        $taskStatuses = Task::query()->pluck('status')->map(fn ($s) => $s->value)->unique()->sort()->values();

        // Matter: only CreateMatter (OPEN) and CompleteMatter (COMPLETED)
        // write status anywhere in the domain — IN_PROGRESS, WAITING, ON_HOLD,
        // ARCHIVED and CANCELLED are unreachable and must never appear here.
        expect($matterStatuses->all())->toBe(['COMPLETED', 'OPEN']);

        // Document: RECEIVED (upload), VERIFIED (verify), ARCHIVED (archive)
        // are the only reachable values (D-117) — DRAFT, UNDER_REVIEW, FINAL
        // and VOID must never appear.
        expect($documentStatuses->all())->toBe(['ARCHIVED', 'RECEIVED', 'VERIFIED']);

        expect($taskStatuses->all())->toBe(['CANCELLED', 'COMPLETED', 'IN_PROGRESS', 'OPEN', 'WAITING']);
    });

    it('gives every document a valid current version', function () {
        app(DemoDataSeeder::class)->seed();

        foreach (Document::query()->get() as $document) {
            expect($document->current_version_id)->not->toBeNull();

            $version = DocumentVersion::query()->find($document->current_version_id);

            expect($version)->not->toBeNull()
                ->and($version->document_id)->toBe($document->getKey());
        }
    });

    it('never writes a storage path that looks public', function () {
        app(DemoDataSeeder::class)->seed();

        foreach (DocumentVersion::query()->pluck('storage_path') as $path) {
            expect($path)->not->toContain('public/')
                ->and($path)->not->toContain('uploads/');
        }
    });

    it('stores every demo document on the local_demo disk, never on local', function () {
        app(DemoDataSeeder::class)->seed();

        $disks = DocumentVersion::query()->pluck('storage_disk')->unique();

        expect($disks->all())->toBe([DemoDataSeeder::DEMO_DISK])
            ->and(Storage::disk('local')->allFiles())->toBe([]);

        foreach (DocumentVersion::query()->pluck('storage_path') as $path) {
            expect(Storage::disk('local_demo')->exists($path))->toBeTrue();
        }
    });

    it('creates no Deed, Workflow, PPAT, or Billing entity', function () {
        app(DemoDataSeeder::class)->seed();

        foreach ([
            'notary_deeds', 'notary_minuta',
            'ppat_deeds', 'properties', 'property_owners', 'ppat_warkah', 'ppat_warkah_items',
            'matter_workflows', 'matter_stage_instances', 'workflow_templates',
            'quotations', 'invoices', 'payments', 'disbursements',
        ] as $table) {
            expect(DB::table($table)->count())->toBe(0);
        }
    });
});

describe('DemoDataSeeder — Organization/Office construction', function () {
    it('constructs Organization and Office directly only in the two reviewed precedents', function (): void {
        // No `CreateOrganization` or `CreateOffice` Action exists anywhere in
        // this codebase — there is nothing to call. `BootstrapDeploymentCommand`
        // (D-034) is the original, already-reviewed precedent for constructing
        // both directly with `new Organization`/`new Office`; DemoDataSeeder
        // follows it deliberately rather than inventing a second pattern. This
        // is a source-level guard, so the invariant survives whatever gets
        // written next — comments are stripped first since prose is not code.
        $allowed = [
            'Console'.DIRECTORY_SEPARATOR.'Commands'.DIRECTORY_SEPARATOR.'BootstrapDeploymentCommand.php',
            'Domains'.DIRECTORY_SEPARATOR.'Demo'.DIRECTORY_SEPARATOR.'DemoDataSeeder.php',
        ];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
        $offenders = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file->getPathname()));

            if (preg_match('/\bnew\s+(Organization|Office)\b/', $code) && ! in_array($relative, $allowed, true)) {
                $offenders[] = $relative;
            }
        }

        expect($offenders)->toBe([]);
    });

    it('gives the demo Organization and Office the same required fields BootstrapDeploymentCommand gives them', function (): void {
        bootstrapLoginReadyRole();
        Storage::fake('local_demo');
        Storage::fake('local');

        app(DemoDataSeeder::class)->seed();

        $office = Office::query()->where('code', DemoDataSeeder::OFFICE_CODE)->firstOrFail();
        $organization = Organization::query()->findOrFail($office->organization_id);

        expect($organization->name)->toBe(DemoDataSeeder::ORGANIZATION_NAME)
            ->and($office->organization_id)->toBe($organization->getKey())
            ->and($office->code)->toBe(DemoDataSeeder::OFFICE_CODE)
            ->and($office->name)->not->toBeEmpty();
    });
});

describe('DemoDataSeeder — role prerequisite (D-045, D-057)', function () {
    beforeEach(function () {
        Storage::fake('local_demo');
        Storage::fake('local');
    });

    it('refuses when no SUPER_ADMIN role exists yet, and leaves nothing behind', function () {
        // Deliberately no bootstrapLoginReadyRole() call — this is a database
        // that has never been through permissions:sync or app:bootstrap.
        expect(Role::query()->where('name', DefaultRoleRegistry::ADMINISTRATOR)->exists())->toBeFalse();

        $before = demoEntityCounts();

        expect(fn () => app(DemoDataSeeder::class)->seed())
            ->toThrow(DemoRolePrerequisiteMissing::class, 'no "SUPER_ADMIN" role exists yet');

        // The failure happens after Organization, Office and Users are
        // written (makeActorAuthorizationCapable() runs right after
        // createUsers()) but the whole run is one transaction, so every one
        // of those writes must be rolled back along with it — this is the
        // same rollback guarantee already proven for a CreateTask failure
        // above, exercised here for a different failure point.
        expect(demoEntityCounts())->toBe($before);
    });

    it('refuses when SUPER_ADMIN exists but grants nothing usable, and leaves nothing behind', function () {
        // A role that exists only by name — no permissions:sync ran, so no
        // Permission row exists for it to hold. This is what an office would
        // see if someone created the role by hand without ever configuring it.
        Role::create(['name' => DefaultRoleRegistry::ADMINISTRATOR, 'guard_name' => PermissionRegistry::GUARD]);

        $before = demoEntityCounts();

        expect(fn () => app(DemoDataSeeder::class)->seed())
            ->toThrow(DemoRolePrerequisiteMissing::class, 'does not currently grant access to');

        expect(demoEntityCounts())->toBe($before);
    });

    it('assigns SUPER_ADMIN to exactly the primary actor, and to no one else', function () {
        bootstrapLoginReadyRole();

        app(DemoDataSeeder::class)->seed();

        $actor = User::query()->where('email', 'notaris.demo@example.test')->firstOrFail();
        $others = User::query()->where('email', '!=', 'notaris.demo@example.test')->get();

        expect($actor->roles()->where('name', DefaultRoleRegistry::ADMINISTRATOR)->exists())->toBeTrue()
            ->and($others)->toHaveCount(4);

        foreach ($others as $other) {
            expect($other->roles()->count())->toBe(0);
        }
    });

    it('makes the primary actor pass every real Policy check the main surfaces require', function () {
        bootstrapLoginReadyRole();

        app(DemoDataSeeder::class)->seed();

        $actor = User::query()->where('email', 'notaris.demo@example.test')->firstOrFail();

        // The same Policy classes DemoDataSeeder itself calls, and the same
        // ones a real Controller resolves from the container — not a
        // reimplementation of the check, the check itself.
        expect(app(IndividualPolicy::class)->viewAny($actor))->toBeTrue()
            ->and(app(CompanyPolicy::class)->viewAny($actor))->toBeTrue()
            ->and(app(ProjectPolicy::class)->viewAny($actor))->toBeTrue()
            ->and(app(MatterPolicy::class)->viewAny($actor, MatterDomain::NOTARY))->toBeTrue()
            ->and(app(MatterPolicy::class)->viewAny($actor, MatterDomain::PPAT))->toBeTrue()
            ->and(app(DocumentPolicy::class)->viewAny($actor))->toBeTrue()
            ->and(app(TaskPolicy::class)->viewAny($actor))->toBeTrue();
    });
});
