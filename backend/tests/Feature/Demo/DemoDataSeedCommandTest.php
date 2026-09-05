<?php

use App\Domains\Demo\DemoDataSeeder;
use App\Domains\Demo\Exceptions\DemoDatasetAlreadyExists;
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
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
 */
beforeEach(function (): void {
    expect(DB::connection()->getDatabaseName())->not->toBe('notary_ppat_office');
});

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

    it('refuses a second run and changes nothing', function () {
        app(DemoDataSeeder::class)->seed();

        $before = [
            'organizations' => Organization::query()->count(),
            'offices' => Office::query()->count(),
            'users' => User::query()->count(),
            'parties' => Party::query()->count(),
            'projects' => Project::query()->count(),
            'matters' => Matter::query()->count(),
            'documents' => Document::query()->count(),
            'tasks' => Task::query()->count(),
        ];

        expect(fn () => app(DemoDataSeeder::class)->seed())
            ->toThrow(DemoDatasetAlreadyExists::class);

        expect([
            'organizations' => Organization::query()->count(),
            'offices' => Office::query()->count(),
            'users' => User::query()->count(),
            'parties' => Party::query()->count(),
            'projects' => Project::query()->count(),
            'matters' => Matter::query()->count(),
            'documents' => Document::query()->count(),
            'tasks' => Task::query()->count(),
        ])->toBe($before);
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
