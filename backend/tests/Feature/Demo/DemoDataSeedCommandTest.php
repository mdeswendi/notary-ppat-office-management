<?php

use App\Console\Commands\DemoDataSeedCommand;
use App\Domains\Authorization\DefaultRoleRegistry;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\SyncCanonicalPermissions;
use App\Domains\Dashboard\Services\DashboardAggregator;
use App\Domains\Demo\DemoDataSeeder;
use App\Domains\Demo\Exceptions\DemoDatasetAlreadyExists;
use App\Domains\Demo\Exceptions\DemoPrimaryActorPasswordInvalid;
use App\Domains\Demo\Exceptions\DemoRolePrerequisiteMissing;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Notary\Actions\FileMinuta;
use App\Domains\Notary\Actions\FinalizeNotaryDeed;
use App\Domains\Notary\Enums\NotaryDeedStatus;
use App\Domains\Task\Actions\CreateTask;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Individual;
use App\Models\Matter;
use App\Models\NotaryDeed;
use App\Models\NotaryMinuta;
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
use App\Policies\NotaryDeedPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * A password that satisfies PasswordRules::forNewPassword() in the test
 * environment — Password::default()'s minimum length, with `.uncompromised()`
 * skipped by that class itself (`app()->runningUnitTests()`), exactly as it
 * is for every other test in this codebase that sets a password.
 */
const DEMO_PRIMARY_PASSWORD = 'CorrectHorseBatteryStaple123!';

/**
 * Invokes `DemoDataSeedCommand::collectPrimaryActorPassword()` directly via
 * Reflection — the "unit-level command interaction test" the task called
 * for. This method never touches `$this->input`/`$this->output`,
 * `DemoEnvironmentGuard`, or the database on its success path (it is a pure
 * function of `$interactive` and `$ask`), so a bare `new DemoDataSeedCommand`
 * with no console or Artisan machinery attached is enough to exercise it —
 * entirely independent of the guard that makes a full end-to-end
 * `demo:seed` prompt impossible to drive in this suite (see the file-level
 * docblock above).
 *
 * @param  Closure(string): string  $ask
 */
function collectPrimaryActorPassword(bool $interactive, Closure $ask): string
{
    $method = new ReflectionMethod(DemoDataSeedCommand::class, 'collectPrimaryActorPassword');
    $method->setAccessible(true);

    return $method->invoke(new DemoDataSeedCommand, $interactive, $ask);
}

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
 *
 * The same limitation applies to the primary demo user's password prompt
 * (`DemoDataSeedCommand::collectPrimaryActorPassword()`): no test here can
 * drive it *through* `$this->artisan('demo:seed')->expectsQuestion(...)`,
 * because the guard refuses before the prompt is ever reached. That method
 * is deliberately written as a pure function of an `$interactive` flag and
 * an `$ask` closure for exactly this reason — the `collectPrimaryActorPassword()`
 * helper function below calls it directly via Reflection, with a bare
 * `DemoDataSeedCommand` instance and no console attached, which is what the
 * task that introduced it called a "unit-level command interaction test."
 * The guard itself is never touched, weakened, or bypassed to make this
 * possible — this method simply never depended on it in the first place.
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
        'notary_deeds' => NotaryDeed::query()->count(),
        'notary_minuta' => DB::table('notary_minuta')->count(),
    ];
}

/**
 * Seeds the dataset and returns every Notary Deed keyed by its own title —
 * titles are unique per run ('Akta Notaris Demo 1'..'4'), so this avoids
 * relying on creation-timestamp ordering, which SQLite can tie within a
 * single fast test.
 *
 * @return array<string, NotaryDeed>
 */
function seedNotaryDeedsByTitle(): array
{
    app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

    return NotaryDeed::query()->get()->keyBy('title')->all();
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

    it('exits non-zero under --no-interaction without ever prompting', function () {
        // The guard rejects first regardless (see the file docblock above),
        // so this cannot isolate "refused because non-interactive" from
        // "refused because the wrong environment" the way a bootstrapped
        // local/notary_ppat_demo run could. What it does prove: the full
        // pipeline exits cleanly under --no-interaction rather than hanging
        // on a prompt or crashing, and — because no expectsQuestion() is
        // queued here — that no prompt is attempted along the way (Laravel's
        // console test double throws if an unexpected question is asked).
        // collectPrimaryActorPassword()'s own non-interactive behaviour is
        // proven directly, and independently of the guard, in the
        // "primary actor password" describe block below.
        $this->artisan('demo:seed', ['--no-interaction' => true])
            ->assertExitCode(Command::FAILURE);

        expect(Organization::query()->count())->toBe(0)
            ->and(Office::query()->count())->toBe(0)
            ->and(User::query()->count())->toBe(0);
    });
});

describe('DemoDataSeedCommand — primary actor password (unit-level)', function () {
    it('never asks a question when the run is not interactive', function () {
        $asked = [];

        $password = null;
        $thrown = null;

        try {
            $password = collectPrimaryActorPassword(
                interactive: false,
                ask: function (string $question) use (&$asked): string {
                    $asked[] = $question;

                    throw new LogicException('the ask closure must never be called when not interactive');
                },
            );
        } catch (DemoPrimaryActorPasswordInvalid $e) {
            $thrown = $e;
        }

        expect($password)->toBeNull()
            ->and($asked)->toBe([])
            ->and($thrown)->not->toBeNull()
            ->and($thrown->getMessage())->toContain('not interactive');
    });

    it('accepts a matching, policy-valid password typed twice', function () {
        $password = collectPrimaryActorPassword(
            interactive: true,
            ask: fn (string $question): string => DEMO_PRIMARY_PASSWORD,
        );

        expect($password)->toBe(DEMO_PRIMARY_PASSWORD);
    });

    it('refuses two different answers, and never echoes either of them', function () {
        $answers = [DEMO_PRIMARY_PASSWORD, 'CompletelyDifferentPassword987!'];
        $call = 0;

        $thrown = null;

        try {
            collectPrimaryActorPassword(
                interactive: true,
                ask: function () use (&$call, $answers): string {
                    return $answers[$call++];
                },
            );
        } catch (DemoPrimaryActorPasswordInvalid $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown->getMessage())->not->toContain(DEMO_PRIMARY_PASSWORD)
            ->and($thrown->getMessage())->not->toContain('CompletelyDifferentPassword987!');
    });

    it('refuses an empty password', function () {
        $thrown = null;

        try {
            collectPrimaryActorPassword(interactive: true, ask: fn (): string => '');
        } catch (DemoPrimaryActorPasswordInvalid $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull();
    });

    it('refuses a password that fails the shared password policy', function () {
        // Password::default()'s minimum length is the one rule active in
        // this environment (PasswordRules skips .uncompromised() under
        // app()->runningUnitTests()) — a one-character answer fails it, and
        // matches itself, so this isolates the policy check from the
        // confirmation check.
        $thrown = null;

        try {
            collectPrimaryActorPassword(interactive: true, ask: fn (): string => 'x');
        } catch (DemoPrimaryActorPasswordInvalid $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown->getMessage())->not->toContain('x');
    });

    it('refuses when the prompt is cancelled or unavailable, without an uncaught exception', function () {
        $thrown = null;

        try {
            collectPrimaryActorPassword(
                interactive: true,
                ask: fn (): string => throw new RuntimeException('simulated: input stream closed'),
            );
        } catch (DemoPrimaryActorPasswordInvalid $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown->getMessage())->not->toContain('simulated');
    });

    it('never carries a password or its confirmation in any of its exception messages', function () {
        // Source-level guard: DemoPrimaryActorPasswordInvalid's factories
        // must never accept, interpolate, or forward a password value.
        $source = file_get_contents(app_path('Domains/Demo/Exceptions/DemoPrimaryActorPasswordInvalid.php'));

        expect($source)->not->toContain('$password')
            ->and($source)->not->toContain('$confirmation');
    });

    it('never passes the password or its confirmation to a console output call', function () {
        // Source-level guard covering the paths a unit test cannot reach
        // (the guard blocks a real run before the command's own success or
        // error output is ever printed — see the file docblock above).
        $source = file_get_contents(app_path('Console/Commands/DemoDataSeedCommand.php'));
        $lines = explode("\n", $source);
        $offenders = [];

        foreach ($lines as $number => $line) {
            $isOutputCall = preg_match('/\$this->(info|error|line|warn|comment|table|newLine|alert)\s*\(/', $line);
            $namesPassword = str_contains($line, '$password') || str_contains($line, '$confirmation');

            if ($isOutputCall && $namesPassword) {
                $offenders[] = ($number + 1).': '.trim($line);
            }
        }

        expect($offenders)->toBe([]);
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
        $result = app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        expect($result->officeCode)->toBe(DemoDataSeeder::OFFICE_CODE)
            ->and($result->users)->toBe(5)
            ->and($result->parties)->toBe(9)
            ->and($result->projects)->toBe(3)
            ->and($result->matters)->toBe(3)
            // 6 ordinary demo Documents plus one filed as the Minuta's own
            // record — see createNotaryMinuta().
            ->and($result->documents)->toBe(7)
            ->and($result->tasks)->toBe(6)
            ->and($result->notaryDeeds)->toBe(4)
            ->and($result->notaryMinutas)->toBe(1);

        expect(Organization::query()->count())->toBe(1)
            ->and(Office::query()->count())->toBe(1)
            ->and(User::query()->count())->toBe(5)
            ->and(Party::query()->count())->toBe(9)
            ->and(Individual::query()->count())->toBe(6)
            ->and(Company::query()->count())->toBe(3)
            ->and(Project::query()->count())->toBe(3)
            ->and(Matter::query()->count())->toBe(3)
            ->and(Document::query()->count())->toBe(7)
            ->and(Task::query()->count())->toBe(6)
            ->and(NotaryDeed::query()->count())->toBe(4)
            ->and(DB::table('notary_minuta')->count())->toBe(1);
    });

    it('refuses a second run, throwing before any write, and changes nothing', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $before = demoEntityCounts();

        expect(fn () => app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD))
            ->toThrow(DemoDatasetAlreadyExists::class);

        expect(demoEntityCounts())->toBe($before);
    });

    it('leaves no partial dataset, and no orphaned demo files, when orchestration fails midway', function () {
        // CreateTask is the last kind of record the seeder writes, so
        // failing it exercises the full chain of prior work being rolled
        // back — Projects, Matters, Documents and Notary Deeds included.
        $this->mock(CreateTask::class, function ($mock) {
            $mock->shouldReceive('handle')->andThrow(new RuntimeException('simulated mid-run failure'));
        });

        expect(fn () => app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD))->toThrow(RuntimeException::class);

        expect(Organization::query()->count())->toBe(0)
            ->and(Office::query()->count())->toBe(0)
            ->and(User::query()->count())->toBe(0)
            ->and(Party::query()->count())->toBe(0)
            ->and(Project::query()->count())->toBe(0)
            ->and(Matter::query()->count())->toBe(0)
            ->and(Document::query()->count())->toBe(0)
            ->and(NotaryDeed::query()->count())->toBe(0)
            ->and(Activity::query()->count())->toBe(0)
            // The six documents already uploaded before CreateTask ran had
            // their rows rolled back by the transaction; the cleanup path
            // must also have cleared the disk those rows pointed at.
            ->and(Storage::disk('local_demo')->allFiles())->toBe([]);
    });

    it('leaves no partial dataset when a Notary Deed lifecycle Action fails midway', function () {
        // FinalizeNotaryDeed is the last of the four deed-lifecycle Actions
        // this class calls, so failing it exercises the full chain being
        // rolled back — the three earlier deeds (DRAFT, UNDER_REVIEW,
        // APPROVED) included, not only the one being finalized.
        $this->mock(FinalizeNotaryDeed::class, function ($mock) {
            $mock->shouldReceive('handle')->andThrow(new RuntimeException('simulated mid-run failure'));
        });

        expect(fn () => app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD))->toThrow(RuntimeException::class);

        expect(Organization::query()->count())->toBe(0)
            ->and(Office::query()->count())->toBe(0)
            ->and(Matter::query()->count())->toBe(0)
            ->and(NotaryDeed::query()->count())->toBe(0)
            ->and(Task::query()->count())->toBe(0);
    });

    it('never sets nik, npwp, or tax_id on any created party', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        expect(Individual::query()->whereNotNull('nik')->count())->toBe(0)
            ->and(Individual::query()->whereNotNull('npwp')->count())->toBe(0)
            ->and(Company::query()->whereNotNull('tax_id')->count())->toBe(0);
    });

    it('allocates project, matter, and document numbers rather than hardcoding them', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

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
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

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
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

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
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        foreach (Document::query()->get() as $document) {
            expect($document->current_version_id)->not->toBeNull();

            $version = DocumentVersion::query()->find($document->current_version_id);

            expect($version)->not->toBeNull()
                ->and($version->document_id)->toBe($document->getKey());
        }
    });

    it('never writes a storage path that looks public', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        foreach (DocumentVersion::query()->pluck('storage_path') as $path) {
            expect($path)->not->toContain('public/')
                ->and($path)->not->toContain('uploads/');
        }
    });

    it('stores every demo document on the local_demo disk, never on local', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $disks = DocumentVersion::query()->pluck('storage_disk')->unique();

        expect($disks->all())->toBe([DemoDataSeeder::DEMO_DISK])
            ->and(Storage::disk('local')->allFiles())->toBe([]);

        foreach (DocumentVersion::query()->pluck('storage_path') as $path) {
            expect(Storage::disk('local_demo')->exists($path))->toBeTrue();
        }
    });

    it('creates no Workflow, PPAT, or Billing entity', function () {
        // notary_deeds and notary_minuta are deliberately absent from this
        // list — see the "DemoDataSeeder — Notary Deeds" and "— Notary
        // Minuta" describe blocks below for what each contains and does not.
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        foreach ([
            'ppat_deeds', 'properties', 'property_owners', 'ppat_warkah', 'ppat_warkah_items',
            'matter_workflows', 'matter_stage_instances', 'workflow_templates',
            'quotations', 'invoices', 'payments', 'disbursements',
        ] as $table) {
            expect(DB::table($table)->count())->toBe(0);
        }
    });
});

describe('DemoDataSeeder — primary actor login credentials', function () {
    beforeEach(function () {
        Storage::fake('local_demo');
        Storage::fake('local');
        bootstrapLoginReadyRole();
    });

    it('stores the primary actor password as a hash, never as plaintext', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $actor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();

        expect($actor->password)->not->toBe(DEMO_PRIMARY_PASSWORD)
            ->and($actor->password)->not->toContain(DEMO_PRIMARY_PASSWORD);
    });

    it('hashes the primary actor password so it verifies against the password given', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $actor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();

        expect(Hash::check(DEMO_PRIMARY_PASSWORD, $actor->password))->toBeTrue();
    });

    it('never lets a supporting user authenticate with the primary actor password', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $others = User::query()->where('email', '!=', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->get();

        expect($others)->toHaveCount(4);

        foreach ($others as $other) {
            expect(Hash::check(DEMO_PRIMARY_PASSWORD, $other->password))->toBeFalse();
        }
    });

    it('never writes the password anywhere on DemoSeedResult', function () {
        $result = app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        expect(array_keys(get_object_vars($result)))->toBe([
            'officeCode', 'users', 'parties', 'projects', 'matters', 'documents', 'tasks', 'notaryDeeds', 'notaryMinutas',
        ]);

        foreach (get_object_vars($result) as $value) {
            if (is_string($value)) {
                expect($value)->not->toBe(DEMO_PRIMARY_PASSWORD);
            }
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

        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

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

        expect(fn () => app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD))
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

        expect(fn () => app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD))
            ->toThrow(DemoRolePrerequisiteMissing::class, 'does not currently grant access to');

        expect(demoEntityCounts())->toBe($before);
    });

    it('assigns SUPER_ADMIN to exactly the primary actor, and to no one else', function () {
        bootstrapLoginReadyRole();

        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

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

        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

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
            ->and(app(TaskPolicy::class)->viewAny($actor))->toBeTrue()
            ->and(app(NotaryDeedPolicy::class)->viewAny($actor))->toBeTrue();
    });
});

describe('DemoDataSeeder — Notary Deeds (Task 3A)', function () {
    beforeEach(function () {
        Storage::fake('local_demo');
        Storage::fake('local');
        bootstrapLoginReadyRole();
    });

    it('creates exactly one deed per target status — DRAFT, UNDER_REVIEW, APPROVED, FINALIZED', function () {
        $deeds = seedNotaryDeedsByTitle();

        expect($deeds)->toHaveCount(4);

        $statuses = collect($deeds)->map(fn (NotaryDeed $deed) => $deed->status->value)->sort()->values()->all();

        expect($statuses)->toBe(['APPROVED', 'DRAFT', 'FINALIZED', 'UNDER_REVIEW']);
    });

    it('never reaches VOID or SUPERSEDED, which no Action produces', function () {
        $deeds = seedNotaryDeedsByTitle();

        foreach ($deeds as $deed) {
            expect($deed->status)->not->toBe(NotaryDeedStatus::VOID)
                ->and($deed->status)->not->toBe(NotaryDeedStatus::SUPERSEDED);
        }
    });

    it('never sets deed_number, deed_date, or deed_type_code on any deed', function () {
        // deed_number: only RecordNotaryDeedNumber writes it, and this class
        // never calls that Action — a FINALIZED deed with none is explicitly
        // permitted (D-120). deed_date and deed_type_code: no canonical value
        // exists for either (M6 seeds no deed-type catalogue), so both stay
        // null exactly as NotaryDeedFactory's own definition leaves them.
        $deeds = seedNotaryDeedsByTitle();

        foreach ($deeds as $deed) {
            expect($deed->deed_number)->toBeNull()
                ->and($deed->deed_date)->toBeNull()
                ->and($deed->deed_type_code)->toBeNull();
        }
    });

    it('keeps every deed in the demo office, with a valid NOTARY Matter parent', function () {
        $deeds = seedNotaryDeedsByTitle();
        $office = Office::query()->where('code', DemoDataSeeder::OFFICE_CODE)->firstOrFail();

        foreach ($deeds as $deed) {
            expect($deed->office_id)->toBe($office->getKey());

            $matter = Matter::query()->find($deed->matter_id);

            expect($matter)->not->toBeNull()
                ->and($matter->office_id)->toBe($office->getKey())
                ->and($matter->domain)->toBe(MatterDomain::NOTARY);
        }
    });

    it('leaves no orphaned deed — every matter_id resolves to a Matter that still exists', function () {
        $deeds = seedNotaryDeedsByTitle();

        foreach ($deeds as $deed) {
            expect($deed->matter_id)->not->toBeNull();
            expect(Matter::query()->whereKey($deed->matter_id)->exists())->toBeTrue();
        }
    });

    it('distributes the four deeds across the two existing NOTARY Matters rather than creating a new one', function () {
        seedNotaryDeedsByTitle();

        // Task 2 already creates exactly two NOTARY Matters (one PPAT). If
        // this method needed a Matter of its own, that count would be three.
        expect(Matter::query()->where('domain', MatterDomain::NOTARY->value)->count())->toBe(2)
            ->and(Matter::query()->count())->toBe(3);

        foreach (Matter::query()->where('domain', MatterDomain::NOTARY->value)->get() as $matter) {
            expect($matter->notaryDeeds()->count())->toBeGreaterThan(0);
        }
    });

    it('gives every Matter linked to a deed at least one demo Party, transitively satisfying appearing-party data', function () {
        // NotaryDeed itself carries no appearing-party column (M6.1) — a
        // deed's participants are its parent Matter's, exactly as
        // NotaryDeedVisibility's OWN/ASSIGNED predicates already resolve
        // through the Matter rather than the deed. Task 2's
        // linkMatterParties() already attaches one Party to every Matter;
        // this proves that holds for the two Matters these deeds use.
        $deeds = seedNotaryDeedsByTitle();

        $matterIds = collect($deeds)->map(fn (NotaryDeed $deed) => $deed->matter_id)->unique();

        foreach ($matterIds as $matterId) {
            expect(DB::table('matter_parties')->where('matter_id', $matterId)->exists())->toBeTrue();
        }
    });

    it('writes each act-pair together and only up to the deed\'s own status — never ahead of it', function () {
        $deeds = seedNotaryDeedsByTitle();

        $draft = $deeds['Akta Notaris Demo 1'];
        $underReview = $deeds['Akta Notaris Demo 2'];
        $approved = $deeds['Akta Notaris Demo 3'];
        $finalized = $deeds['Akta Notaris Demo 4'];

        expect($draft->status)->toBe(NotaryDeedStatus::DRAFT)
            ->and($draft->reviewed_at)->toBeNull()->and($draft->reviewed_by)->toBeNull()
            ->and($draft->approved_at)->toBeNull()->and($draft->approved_by)->toBeNull()
            ->and($draft->finalized_at)->toBeNull()->and($draft->finalized_by)->toBeNull();

        expect($underReview->status)->toBe(NotaryDeedStatus::UNDER_REVIEW)
            ->and($underReview->reviewed_at)->not->toBeNull()->and($underReview->reviewed_by)->not->toBeNull()
            ->and($underReview->approved_at)->toBeNull()->and($underReview->approved_by)->toBeNull()
            ->and($underReview->finalized_at)->toBeNull()->and($underReview->finalized_by)->toBeNull();

        expect($approved->status)->toBe(NotaryDeedStatus::APPROVED)
            ->and($approved->reviewed_at)->not->toBeNull()->and($approved->reviewed_by)->not->toBeNull()
            ->and($approved->approved_at)->not->toBeNull()->and($approved->approved_by)->not->toBeNull()
            ->and($approved->finalized_at)->toBeNull()->and($approved->finalized_by)->toBeNull();

        expect($finalized->status)->toBe(NotaryDeedStatus::FINALIZED)
            ->and($finalized->reviewed_at)->not->toBeNull()->and($finalized->reviewed_by)->not->toBeNull()
            ->and($finalized->approved_at)->not->toBeNull()->and($finalized->approved_by)->not->toBeNull()
            ->and($finalized->finalized_at)->not->toBeNull()->and($finalized->finalized_by)->not->toBeNull();

        // Every actor recorded is the one primary actor this dataset ever
        // authorizes to act — never one of the four role-less supporting
        // users, which would be a sign the wrong actor reached an Action.
        $primaryActor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();

        foreach ([$underReview->reviewed_by, $approved->reviewed_by, $approved->approved_by,
            $finalized->reviewed_by, $finalized->approved_by, $finalized->finalized_by] as $actorId) {
            expect($actorId)->toBe($primaryActor->getKey());
        }
    });

    it('attaches a demo Document to the FINALIZED deed, on the demo disk and never public', function () {
        $deeds = seedNotaryDeedsByTitle();
        $finalized = $deeds['Akta Notaris Demo 4'];

        expect($finalized->final_document_id)->not->toBeNull()
            ->and($finalized->draft_document_id)->toBeNull()
            ->and($finalized->minuta_document_id)->toBeNull();

        $document = Document::query()->findOrFail($finalized->final_document_id);

        expect($document->office_id)->toBe($finalized->office_id)
            // The same Document Task 2 already links (via matter_documents)
            // to this deed's own Matter — not an arbitrary one — so the
            // narrative stays coherent.
            ->and($document->matters()->whereKey($finalized->matter_id)->exists())->toBeTrue();

        $version = DocumentVersion::query()->findOrFail($document->current_version_id);

        expect($version->storage_disk)->toBe(DemoDataSeeder::DEMO_DISK)
            ->and($version->storage_path)->not->toContain('public/')
            ->and(Storage::disk('local_demo')->exists($version->storage_path))->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('never attaches a Document to the three deeds that do not need one for the detail screenshot', function () {
        $deeds = seedNotaryDeedsByTitle();

        foreach (['Akta Notaris Demo 1', 'Akta Notaris Demo 2', 'Akta Notaris Demo 3'] as $title) {
            $deed = $deeds[$title];

            expect($deed->draft_document_id)->toBeNull()
                ->and($deed->final_document_id)->toBeNull()
                ->and($deed->minuta_document_id)->toBeNull();
        }
    });

    it('records exactly the activity timeline each deed\'s lifecycle actually went through', function () {
        $deeds = seedNotaryDeedsByTitle();

        $expected = [
            'Akta Notaris Demo 1' => ['DEED_CREATED'],
            'Akta Notaris Demo 2' => ['DEED_CREATED', 'DEED_REVIEWED'],
            'Akta Notaris Demo 3' => ['DEED_CREATED', 'DEED_REVIEWED', 'DEED_APPROVED'],
            'Akta Notaris Demo 4' => ['DEED_CREATED', 'DEED_REVIEWED', 'DEED_APPROVED', 'DEED_FINALIZED'],
        ];

        foreach ($expected as $title => $types) {
            $deed = $deeds[$title];

            $recorded = Activity::query()
                ->forSubject(NotaryDeed::class, $deed->getKey())
                ->orderBy('created_at')
                ->pluck('activity_type')
                ->map(fn ($type) => $type->value)
                ->all();

            expect($recorded)->toBe($types);
        }
    });

    it('lets the primary actor create, review, approve, and finalize under the real Policy', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $actor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();
        $notaryMatter = Matter::query()->where('domain', MatterDomain::NOTARY->value)->firstOrFail();
        $draftDeed = NotaryDeed::query()->where('title', 'Akta Notaris Demo 1')->firstOrFail();
        $underReviewDeed = NotaryDeed::query()->where('title', 'Akta Notaris Demo 2')->firstOrFail();
        $approvedDeed = NotaryDeed::query()->where('title', 'Akta Notaris Demo 3')->firstOrFail();

        $policy = app(NotaryDeedPolicy::class);

        expect($policy->viewAny($actor))->toBeTrue()
            ->and($policy->create($actor, $notaryMatter))->toBeTrue()
            ->and($policy->review($actor, $draftDeed))->toBeTrue()
            ->and($policy->approve($actor, $underReviewDeed))->toBeTrue()
            ->and($policy->finalize($actor, $approvedDeed))->toBeTrue();
    });

    it('refuses a role-less supporting user under the same real Policy that authorized the primary actor', function () {
        // Confirms the seeded dataset is not "authorized because nobody
        // checks" — a different, unprivileged user in the very same office
        // is refused by the production Policy the same way a real Controller
        // would refuse them, even though DemoDataSeeder itself never calls
        // this Policy for its own Actions (CLAUDE.md §35 — the Action trusts
        // its caller already authorized).
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $supportingUser = User::query()->where('email', '!=', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();
        $notaryMatter = Matter::query()->where('domain', MatterDomain::NOTARY->value)->firstOrFail();
        $draftDeed = NotaryDeed::query()->where('title', 'Akta Notaris Demo 1')->firstOrFail();

        expect($supportingUser->roles()->count())->toBe(0);

        $policy = app(NotaryDeedPolicy::class);

        expect($policy->viewAny($supportingUser))->toBeFalse()
            ->and($policy->create($supportingUser, $notaryMatter))->toBeFalse()
            ->and($policy->review($supportingUser, $draftDeed))->toBeFalse();
    });

    it('never displays a deed number, plaintext password, hash, or storage path in the command class source', function () {
        // The command never reads notary_deeds.deed_number at all — this
        // dataset never assigns one, and the summary table only ever prints
        // DemoSeedResult's plain counts.
        $source = file_get_contents(app_path('Console/Commands/DemoDataSeedCommand.php'));

        expect($source)->not->toContain('deed_number')
            ->and($source)->not->toContain('storage_path')
            ->and($source)->not->toContain('local_demo');
    });
});

describe('DemoDataSeeder — Dashboard task assignments (Task 3B)', function () {
    beforeEach(function () {
        Storage::fake('local_demo');
        Storage::fake('local');
        bootstrapLoginReadyRole();

        // A fixed instant, exactly like TaskManagementTest's own beforeEach —
        // due_at is computed relative to Date::now() at seed time, so freezing
        // it here makes the today/overdue/upcoming assertions deterministic
        // regardless of when the suite happens to run.
        Date::setTestNow('2026-09-05 09:00:00');
    });

    afterEach(function () {
        // Never leave the test clock running for a later test in this file
        // or another file in the same process.
        Date::setTestNow();
    });

    it('keeps exactly six Tasks, none orphaned, every one assigned inside the demo office', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $office = Office::query()->where('code', DemoDataSeeder::OFFICE_CODE)->firstOrFail();
        $tasks = Task::query()->get();

        expect($tasks)->toHaveCount(6);

        foreach ($tasks as $task) {
            expect($task->office_id)->toBe($office->getKey())
                ->and($task->assigned_to)->not->toBeNull();

            $assignee = User::query()->find($task->assigned_to);

            expect($assignee)->not->toBeNull()
                ->and($assignee->office_id)->toBe($office->getKey());
        }
    });

    it('represents all five canonical Task statuses, with OPEN repeated', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $statuses = Task::query()->pluck('status')->map(fn ($status) => $status->value)->sort()->values();

        expect($statuses->all())->toBe(['CANCELLED', 'COMPLETED', 'IN_PROGRESS', 'OPEN', 'OPEN', 'WAITING']);
    });

    it('writes assigned_to and assigned_by together, and completed_at/completed_by together, never half a pair', function () {
        // The pairing invariant Task's own model guard enforces — proof that
        // every assignment and every completion went through the real
        // Actions (CreateTask's $assignee parameter, CompleteTask), never a
        // direct column write that could leave one side null.
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $primaryActor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();

        foreach (Task::query()->get() as $task) {
            expect($task->assigned_by)->toBe($primaryActor->getKey());
        }

        $completed = Task::query()->where('title', 'Tugas Administratif Demo 4')->firstOrFail();

        expect($completed->status->value)->toBe('COMPLETED')
            ->and($completed->completed_at)->not->toBeNull()
            ->and($completed->completed_by)->toBe($primaryActor->getKey());

        foreach (Task::query()->where('id', '!=', $completed->getKey())->get() as $task) {
            expect($task->completed_at)->toBeNull()
                ->and($task->completed_by)->toBeNull();
        }
    });

    it('fills today, overdue, and upcoming for the primary actor through the real DashboardAggregator', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $actor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();

        $buckets = app(DashboardAggregator::class)->tasks($actor);

        expect($buckets)->not->toBeNull()
            ->and($buckets['overdue'])->toHaveCount(1)
            ->and($buckets['overdue']->first()->title)->toBe('Tugas Administratif Demo 2')
            ->and($buckets['today'])->toHaveCount(1)
            ->and($buckets['today']->first()->title)->toBe('Tugas Administratif Demo 1')
            // "upcoming" is whereBetween(due_at, [now, now + 7 days]) in the
            // aggregator itself, which the "today" Task (due a couple of
            // hours from now) also satisfies — the exact overlap
            // DashboardTest's own "buckets the actor own work by when it is
            // due" test accepts (its "upcoming" count is 2, not 1, for the
            // same three-Task shape). Reproducing it here is reproducing
            // shipped behaviour, not a bug this seeder introduces.
            ->and($buckets['upcoming'])->toHaveCount(2)
            ->and($buckets['upcoming']->pluck('title')->sort()->values()->all())
            ->toBe(['Tugas Administratif Demo 1', 'Tugas Administratif Demo 3']);

        $total = $buckets['today']->count() + $buckets['overdue']->count() + $buckets['upcoming']->count();

        expect($total)->toBeGreaterThan(0);
    });

    it('makes Workload non-empty and gives it more than one row through the real DashboardAggregator', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $actor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();

        $workload = app(DashboardAggregator::class)->workload($actor);

        expect($workload)->not->toBeNull()
            ->and($workload)->not->toBe([])
            ->and(count($workload))->toBeGreaterThan(1);
    });

    it('reports the primary actor task_count as exactly the three active Tasks assigned to them', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $actor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();

        $workload = app(DashboardAggregator::class)->workload($actor);
        $row = collect($workload)->firstWhere('user_id', $actor->getKey());

        expect($row)->not->toBeNull()
            ->and($row['task_count'])->toBe(3)
            // Matter.pic_user_id is deliberately left null throughout this
            // dataset (see createMatters()) — Task assignment alone is what
            // clears workload()'s exclusion, not a Matter PIC.
            ->and($row['matter_count'])->toBe(0);
    });

    it('excludes the COMPLETED and CANCELLED Task assignees from Workload, because settled work is not load', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $completedAssignee = User::query()->findOrFail(
            Task::query()->where('title', 'Tugas Administratif Demo 4')->value('assigned_to')
        );
        $cancelledAssignee = User::query()->findOrFail(
            Task::query()->where('title', 'Tugas Administratif Demo 5')->value('assigned_to')
        );

        $actor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();
        $userIds = collect(app(DashboardAggregator::class)->workload($actor))->pluck('user_id');

        expect($userIds)->not->toContain($completedAssignee->getKey())
            ->and($userIds)->not->toContain($cancelledAssignee->getKey());
    });

    it('lets a role-less supporting user be an assignee without granting them any role or permission', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $primaryActor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();

        $assigneeIds = Task::query()->pluck('assigned_to')->unique();

        foreach ($assigneeIds as $assigneeId) {
            $assignee = User::query()->findOrFail($assigneeId);

            if ($assignee->getKey() === $primaryActor->getKey()) {
                continue;
            }

            // A supporting user is a legitimate assignee — the composite
            // foreign key requires only that they share the Task's Office,
            // never a role. Confirms assignment granted them nothing extra.
            expect($assignee->roles()->count())->toBe(0);
        }
    });

    it('refuses a cross-office assignee at the database level, confirming why every demo Task assignee shares one Office', function () {
        // Not a DemoDataSeeder behaviour — this proves the production
        // constraint (`tasks_assigned_to_office_foreign`) that makes an
        // out-of-office assignee structurally unrepresentable, which is
        // exactly why this seeder never needs to guard against it itself.
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $primaryActor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();
        $matter = Matter::query()->where('office_id', $primaryActor->office_id)->firstOrFail();

        $otherOrganization = new Organization;
        $otherOrganization->name = 'Kantor Lain (Uji Lintas Office)';
        $otherOrganization->save();

        $otherOffice = new Office;
        $otherOffice->organization_id = $otherOrganization->getKey();
        $otherOffice->code = 'LAIN-01';
        $otherOffice->name = 'Kantor Lain';
        $otherOffice->save();

        $outsider = User::factory()->for($otherOffice)->create();

        expect(fn () => app(CreateTask::class)->handle(
            $primaryActor,
            ['title' => 'Percobaan Lintas Office'],
            null,
            $matter,
            $outsider,
        ))->toThrow(QueryException::class);

        expect(Task::query()->where('title', 'Percobaan Lintas Office')->exists())->toBeFalse();
    });

    it('touches no Matter PIC — Workload is filled by Task assignment alone', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        expect(Matter::query()->whereNotNull('pic_user_id')->count())->toBe(0);
    });

    /**
     * Calendar-boundary determinism. The describe block's own `beforeEach`
     * already froze the clock to 09:00 — every case here overrides that to a
     * different hour on the same date, specifically the three hours a
     * `now() + N hours` offset would have handled differently: just after
     * midnight (nothing to roll back onto), midday (the ordinary case), and
     * just before midnight (where `now() + 2 hours` used to roll onto
     * tomorrow and empty the "today" bucket). The describe block's own
     * `afterEach` still resets the clock after each of these, pass or fail,
     * so overriding it here needs no extra teardown of its own.
     */
    it('keeps overdue, today, and upcoming non-empty no matter what hour demo:seed runs', function (string $frozenAt) {
        Date::setTestNow($frozenAt);

        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $actor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();
        $buckets = app(DashboardAggregator::class)->tasks($actor);

        expect($buckets)->not->toBeNull();

        expect($buckets['overdue'])->not->toBeEmpty()
            ->and($buckets['overdue']->pluck('title')->all())->toContain('Tugas Administratif Demo 2');

        expect($buckets['today'])->not->toBeEmpty()
            ->and($buckets['today']->pluck('title')->all())->toContain('Tugas Administratif Demo 1');

        // "upcoming" is whereBetween(due_at, [now, now + 7 days]) in the real
        // aggregator, which the "today" Task (the last instant of today, always
        // later than whatever moment "now" is) also satisfies at every one of
        // these three hours — the same overlap DashboardTest's own official
        // test accepts. Two names, not just a non-empty check, so the
        // assertion fails loudly if either due date ever drifted out of range.
        expect($buckets['upcoming'])->not->toBeEmpty()
            ->and($buckets['upcoming']->count())->toBeGreaterThanOrEqual(2)
            ->and($buckets['upcoming']->pluck('title')->all())
            ->toContain('Tugas Administratif Demo 1', 'Tugas Administratif Demo 3');

        // Nothing about which hour the seed ran at may change the shape of
        // the dataset itself.
        expect(Task::query()->count())->toBe(6);

        $statuses = Task::query()->pluck('status')->map(fn ($status) => $status->value)->sort()->values();
        expect($statuses->all())->toBe(['CANCELLED', 'COMPLETED', 'IN_PROGRESS', 'OPEN', 'OPEN', 'WAITING']);

        $workload = app(DashboardAggregator::class)->workload($actor);
        expect(count($workload))->toBeGreaterThanOrEqual(2);
    })->with([
        'awal hari (00:05)' => '2026-09-05 00:05:00',
        'tengah hari (12:00)' => '2026-09-05 12:00:00',
        'menjelang akhir hari (23:30)' => '2026-09-05 23:30:00',
    ]);

    it('creates no Workflow, PPAT, or Billing entity as a side effect of this change', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        foreach ([
            'ppat_deeds', 'properties', 'property_owners', 'ppat_warkah', 'ppat_warkah_items',
            'matter_workflows', 'matter_stage_instances', 'workflow_templates',
            'quotations', 'invoices', 'payments', 'disbursements',
        ] as $table) {
            expect(DB::table($table)->count())->toBe(0);
        }
    });
});

describe('DemoDataSeeder — Notary Minuta (Task 3C)', function () {
    beforeEach(function () {
        Storage::fake('local_demo');
        Storage::fake('local');
        bootstrapLoginReadyRole();
    });

    it('files exactly one Notary Minuta, against Akta Notaris Demo 4 and no other deed', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $finalized = NotaryDeed::query()->where('title', 'Akta Notaris Demo 4')->firstOrFail();
        $others = NotaryDeed::query()->where('id', '!=', $finalized->getKey())->pluck('id');

        expect(NotaryMinuta::query()->count())->toBe(1);

        $minuta = NotaryMinuta::query()->firstOrFail();

        expect($minuta->notary_deed_id)->toBe($finalized->getKey())
            ->and(NotaryMinuta::query()->whereIn('notary_deed_id', $others)->count())->toBe(0);
    });

    it('leaves the FINALIZED deed exactly as it was — no number, date, type, or status change', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $finalized = NotaryDeed::query()->where('title', 'Akta Notaris Demo 4')->firstOrFail();

        expect($finalized->status)->toBe(NotaryDeedStatus::FINALIZED)
            ->and($finalized->isReadOnly())->toBeTrue()
            ->and($finalized->deed_number)->toBeNull()
            ->and($finalized->deed_date)->toBeNull()
            ->and($finalized->deed_type_code)->toBeNull();
    });

    it('was filed through FileMinuta, never a direct model write — every system field bears its mark', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $office = Office::query()->where('code', DemoDataSeeder::OFFICE_CODE)->firstOrFail();
        $finalized = NotaryDeed::query()->where('title', 'Akta Notaris Demo 4')->firstOrFail();
        $minuta = NotaryMinuta::query()->firstOrFail();

        // office_id and notary_deed_id are decided by FileMinuta itself, never
        // accepted from a caller (see its own docblock) — proof this went
        // through the Action rather than a hand-filled row.
        expect($minuta->office_id)->toBe($office->getKey())
            ->and($minuta->office_id)->toBe($finalized->office_id)
            ->and($minuta->notary_deed_id)->toBe($finalized->getKey())
            ->and($minuta->document_id)->not->toBeNull()
            // No shelf metadata is invented — FileMinuta's own default for an
            // empty $attributes array.
            ->and($minuta->archive_location)->toBeNull()
            ->and($minuta->volume_number)->toBeNull()
            ->and($minuta->bundle_number)->toBeNull()
            // Canonical columns nothing in M6 writes — proof no code path
            // other than FileMinuta (which never touches them) could have
            // produced this row.
            ->and($minuta->release_status)->toBeNull()
            ->and($minuta->archived_at)->toBeNull()
            ->and($minuta->archived_by)->toBeNull();
    });

    it('lets the primary actor file and view the Minuta under the real NotaryDeedPolicy', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $actor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();
        $finalized = NotaryDeed::query()->where('title', 'Akta Notaris Demo 4')->firstOrFail();

        $policy = app(NotaryDeedPolicy::class);

        expect($policy->createMinuta($actor, $finalized))->toBeTrue()
            ->and($policy->viewMinuta($actor, $finalized))->toBeTrue();
    });

    it('refuses a role-less supporting user under the same real Policy', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $supportingUser = User::query()->where('email', '!=', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();
        $finalized = NotaryDeed::query()->where('title', 'Akta Notaris Demo 4')->firstOrFail();

        expect($supportingUser->roles()->count())->toBe(0);

        $policy = app(NotaryDeedPolicy::class);

        expect($policy->createMinuta($supportingUser, $finalized))->toBeFalse()
            ->and($policy->viewMinuta($supportingUser, $finalized))->toBeFalse();
    });

    it('stores the Minuta filing Document on local_demo, never on local, and never under a public-looking path', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $minuta = NotaryMinuta::query()->with('document.currentVersion')->firstOrFail();
        $document = $minuta->document;

        expect($document)->not->toBeNull();

        $version = DocumentVersion::query()->findOrFail($document->current_version_id);

        expect($version->storage_disk)->toBe(DemoDataSeeder::DEMO_DISK)
            ->and($version->storage_path)->not->toContain('public/')
            ->and($version->storage_path)->not->toContain('uploads/')
            ->and(Storage::disk('local_demo')->exists($version->storage_path))->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('uploads a real, valid PDF — never plain text wearing a .pdf extension', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $minuta = NotaryMinuta::query()->with('document.currentVersion')->firstOrFail();
        $version = DocumentVersion::query()->findOrFail($minuta->document->current_version_id);

        // mime_type is recorded from the file's actual bytes at upload time
        // (DocumentStorage::store() calls getMimeType(), never trusting the
        // filename) — a renamed .txt would have recorded text/plain here.
        expect($version->mime_type)->toBe('application/pdf');

        $bytes = Storage::disk('local_demo')->get($version->storage_path);

        expect(substr($bytes, 0, 5))->toBe('%PDF-')
            ->and($bytes)->toContain('%%EOF');
    });

    it('carries a plain-text demo marker inside the PDF body, and no sensitive or legal-looking data', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $minuta = NotaryMinuta::query()->with('document.currentVersion')->firstOrFail();
        $version = DocumentVersion::query()->findOrFail($minuta->document->current_version_id);

        $bytes = Storage::disk('local_demo')->get($version->storage_path);

        expect($bytes)->toContain('DOKUMEN DEMO')
            ->toContain('BUKAN DOKUMEN HUKUM');

        foreach (['NIK', 'NPWP', 'nomor akta', 'repertorium', str_repeat('3', 16)] as $needle) {
            expect(strtoupper($bytes))->not->toContain(strtoupper($needle));
        }
    });

    it('returns everything the frontend Minuta section needs from the real endpoint, and nothing it should not', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $actor = User::query()->where('email', DemoDataSeeder::PRIMARY_ACTOR_EMAIL)->firstOrFail();
        $finalized = NotaryDeed::query()->where('title', 'Akta Notaris Demo 4')->firstOrFail();

        $response = $this->actingAs($actor)
            ->getJson("/api/v1/notary/deeds/{$finalized->getKey()}/minuta")
            ->assertOk();

        $response
            ->assertJsonPath('data.notary_deed_id', $finalized->getKey())
            ->assertJsonPath('data.document.title', 'Minuta Akta Demo 4')
            ->assertJsonPath('data.archive_location', null)
            ->assertJsonPath('data.release_status', null)
            ->assertJsonPath('data.can_update', true);

        $document = $response->json('data.document');

        // The Document stub is deliberately minimal (NotaryMinutaResource's
        // own contract) — proof no storage internals ever reach the payload.
        expect(array_keys($document))->toBe(['id', 'document_number', 'title', 'status', 'is_sensitive']);

        $flat = json_encode($response->json());

        expect($flat)->not->toContain('storage_path')
            ->and($flat)->not->toContain('checksum')
            ->and($flat)->not->toContain('local_demo');
    });

    it('records no Activity for the Minuta filing, because FileMinuta writes none', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        $minuta = NotaryMinuta::query()->firstOrFail();

        expect(Activity::query()->where('subject_id', $minuta->getKey())->count())->toBe(0);
    });

    it('leaves no orphaned file and rolls back the whole dataset when filing the Minuta fails', function () {
        // FileMinuta is the last of the two Actions createNotaryMinuta()
        // calls, so failing it exercises the full chain being rolled back —
        // including the Document and its file that UploadDocument had
        // already written just before it, inside the same outer transaction.
        $this->mock(FileMinuta::class, function ($mock) {
            $mock->shouldReceive('handle')->andThrow(new RuntimeException('simulated mid-run failure'));
        });

        expect(fn () => app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD))->toThrow(RuntimeException::class);

        expect(Organization::query()->count())->toBe(0)
            ->and(Office::query()->count())->toBe(0)
            ->and(NotaryDeed::query()->count())->toBe(0)
            ->and(NotaryMinuta::query()->count())->toBe(0)
            ->and(Document::query()->count())->toBe(0)
            ->and(Task::query()->count())->toBe(0)
            ->and(Storage::disk('local_demo')->allFiles())->toBe([]);
    });

    it('creates no Workflow, PPAT, or Billing entity as a side effect of filing the Minuta', function () {
        app(DemoDataSeeder::class)->seed(DEMO_PRIMARY_PASSWORD);

        foreach ([
            'ppat_deeds', 'properties', 'property_owners', 'ppat_warkah', 'ppat_warkah_items',
            'matter_workflows', 'matter_stage_instances', 'workflow_templates',
            'quotations', 'invoices', 'payments', 'disbursements',
        ] as $table) {
            expect(DB::table($table)->count())->toBe(0);
        }
    });
});
