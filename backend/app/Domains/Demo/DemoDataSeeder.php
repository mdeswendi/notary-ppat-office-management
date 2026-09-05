<?php

namespace App\Domains\Demo;

use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Demo\Exceptions\DemoDatasetAlreadyExists;
use App\Domains\Document\Actions\ArchiveDocument;
use App\Domains\Document\Actions\UploadDocument;
use App\Domains\Document\Actions\VerifyDocument;
use App\Domains\Document\AllocateDocumentReference;
use App\Domains\Document\DocumentStorage;
use App\Domains\Identity\Actions\CreateUser;
use App\Domains\Matter\Actions\AddMatterParty;
use App\Domains\Matter\Actions\CompleteMatter;
use App\Domains\Matter\Actions\CreateMatter;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Party\Actions\CreateCompany;
use App\Domains\Party\Actions\CreateIndividual;
use App\Domains\Party\Enums\CompanyEntityType;
use App\Domains\Project\Actions\AddProjectParty;
use App\Domains\Project\Actions\CreateProject;
use App\Domains\Task\Actions\CancelTask;
use App\Domains\Task\Actions\CompleteTask;
use App\Domains\Task\Actions\CreateTask;
use App\Domains\Task\Actions\UpdateTask;
use App\Models\Company;
use App\Models\Document;
use App\Models\Individual;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds one minimal, deterministic, synthetic demo dataset — Organization
 * through Documents and Tasks — entirely through the same Actions the product
 * itself uses (`CreateProject`, `CreateMatter`, `UploadDocument`, `CreateTask`,
 * and their lifecycle siblings), so the dataset it produces is exactly as
 * valid as anything a real office would create: real allocators number its
 * Projects, Matters and Documents; real lifecycle rules gate every status it
 * reaches; real audit/activity rows are written alongside it.
 *
 * **Calling {@see DemoEnvironmentGuard::assertSafe()} before this class does
 * anything is the caller's responsibility, not this class's.** That is a
 * deliberate split, not an oversight: `DemoDataSeedCommand` is documented as
 * the one place that runs the guard, so the check exists exactly once in the
 * codebase rather than once per caller (a duplicated check drifts the moment
 * one copy is updated and the other is not). Calling {@see seed()} directly
 * without the command in front of it — from a test, say — bypasses the guard
 * the same way calling any other Action directly bypasses the Policy a
 * Controller would otherwise have run first.
 *
 * **Refuse-not-overwrite.** A single marker — an `Office` row whose `code` is
 * {@see self::OFFICE_CODE} — decides whether a dataset already exists.
 * Finding it throws {@see DemoDatasetAlreadyExists} before a single query
 * beyond that check runs, let alone a transaction opening or a write
 * happening: rerunning this against an already-seeded demo database must
 * never touch a row someone has since edited by hand.
 *
 * **No sensitive identity, ever.** `nik`, `npwp` and `tax_id` are never set on
 * any Party this class creates — not blanked, not faked, simply never present
 * in the attribute arrays passed to {@see CreateIndividual}/{@see CreateCompany},
 * so the columns stay `null` exactly as a fresh Individual/Company row without
 * identity data already does.
 *
 * **No legal numbering is invented.** `project_number`, `matter_number` and
 * `document_number` all come from the same allocators production uploads and
 * creates use — this class supplies no number itself, anywhere. Deed, Warkah,
 * Property, Workflow, Quotation, Invoice and Payment entities are entirely out
 * of scope for this dataset; see the class this ships alongside,
 * `DemoDataSeedCommand`, for what is and is not built.
 */
class DemoDataSeeder
{
    /**
     * Not unique at the database level (Office.code carries no uniqueness
     * constraint), so this is an application-level marker only. Distinctive on
     * purpose — it must never collide with anything a real office, or the
     * existing development fixtures, would plausibly choose.
     */
    public const OFFICE_CODE = 'DEMO-01';

    public const ORGANIZATION_NAME = 'Kantor Notaris & PPAT Demo';

    /**
     * Never `local`. Demo documents must not be reachable through, or mixed
     * with, the disk a real Document's bytes live on.
     */
    public const DEMO_DISK = 'local_demo';

    public function __construct(
        private readonly CreateUser $createUser,
        private readonly CreateIndividual $createIndividual,
        private readonly CreateCompany $createCompany,
        private readonly CreateProject $createProject,
        private readonly AddProjectParty $addProjectParty,
        private readonly CreateMatter $createMatter,
        private readonly AddMatterParty $addMatterParty,
        private readonly CompleteMatter $completeMatter,
        private readonly AllocateDocumentReference $documentAllocator,
        private readonly EventRecorder $events,
        private readonly VerifyDocument $verifyDocument,
        private readonly ArchiveDocument $archiveDocument,
        private readonly CreateTask $createTask,
        private readonly CompleteTask $completeTask,
        private readonly CancelTask $cancelTask,
        private readonly UpdateTask $updateTask,
    ) {}

    /**
     * @throws DemoDatasetAlreadyExists when the marker Office already exists —
     *                                  nothing is read or written beyond that
     *                                  one check
     */
    public function seed(): DemoSeedResult
    {
        if (Office::query()->where('code', self::OFFICE_CODE)->exists()) {
            throw DemoDatasetAlreadyExists::markedBy(self::OFFICE_CODE);
        }

        try {
            return DB::transaction(fn (): DemoSeedResult => $this->build());
        } catch (Throwable $e) {
            // The transaction above already rolled back every row. Files
            // written to the demo disk during this run are not covered by
            // that rollback — filesystem writes are never transactional — so
            // clear the one disk this run could have touched. Safe precisely
            // because DEMO_DISK is never shared with anything else: nothing
            // real is ever stored there, so wiping it clears only what this
            // run itself wrote.
            $this->cleanupDemoDisk();

            throw $e;
        }
    }

    private function build(): DemoSeedResult
    {
        $organization = new Organization;
        $organization->name = self::ORGANIZATION_NAME;
        $organization->save();

        $office = new Office;
        $office->organization_id = $organization->getKey();
        $office->code = self::OFFICE_CODE;
        $office->name = 'Kantor Notaris & PPAT Demo — Kantor Pusat';
        $office->save();

        $users = $this->createUsers($office);
        $actor = $users[0];

        $parties = $this->createParties($actor, $office);

        $projects = $this->createProjects($actor);
        $this->linkProjectParties($actor, $projects, $parties);

        $matters = $this->createMatters($actor, $projects);
        $this->linkMatterParties($actor, $matters, $parties);

        $documents = $this->createDocuments($actor, $projects, $matters);
        $tasks = $this->createTasks($actor, $projects, $matters);

        return new DemoSeedResult(
            officeCode: self::OFFICE_CODE,
            users: count($users),
            parties: count($parties),
            projects: count($projects),
            matters: count($matters),
            documents: count($documents),
            tasks: count($tasks),
        );
    }

    /**
     * @return array<int, User>
     */
    private function createUsers(Office $office): array
    {
        // No role is assigned to any of these (CreateUser grants none — see
        // its own docblock). None of the Actions this class calls checks a
        // permission or a role; every one authorizes nothing itself and
        // trusts that its caller already did (`CLAUDE.md` §35 — that caller
        // is ordinarily a Controller, and here it is this class, calling
        // Actions directly the same way a test in this codebase already does
        // for `CreateMatter`). Role-readiness for an actual login session is
        // therefore explicitly out of scope for this dataset.
        $people = [
            ['name' => 'Notaris Demo', 'email' => 'notaris.demo@example.test'],
            ['name' => 'PPAT Staff Demo', 'email' => 'ppat.staff.demo@example.test'],
            ['name' => 'Staf Administrasi Demo', 'email' => 'admin.staff.demo@example.test'],
            ['name' => 'Petugas Arsip Demo', 'email' => 'arsip.demo@example.test'],
            ['name' => 'Front Office Demo', 'email' => 'frontoffice.demo@example.test'],
        ];

        return array_map(
            fn (array $person): User => $this->createUser->handle([
                'name' => $person['name'],
                'email' => $person['email'],
                'phone' => null,
                'office_id' => $office->getKey(),
                // Random per run, never reused, never logged, never printed —
                // hashed immediately by the model's `hashed` cast. Nothing in
                // this dataset is meant to be logged into; a fixed or
                // guessable password would be a credential left in source for
                // no reason this dataset needs.
                'password' => Str::random(32),
            ]),
            $people,
        );
    }

    /**
     * @return array<int, Individual|Company>
     */
    private function createParties(User $actor, Office $office): array
    {
        $individualNames = [
            'Budi Contoh', 'Siti Contoh', 'Andi Uji', 'Rina Uji', 'Dewi Demo', 'Agus Demo',
        ];

        $companies = [
            ['legal_name' => 'PT Contoh Sejahtera', 'entity_type' => CompanyEntityType::PT],
            ['legal_name' => 'PT Uji Makmur', 'entity_type' => CompanyEntityType::PT],
            ['legal_name' => 'CV Demo Abadi', 'entity_type' => CompanyEntityType::CV],
        ];

        $individuals = array_map(
            // nik/npwp are simply absent from this array, which is what
            // leaves both columns null — the same state a fresh Individual
            // without identity data already has.
            fn (string $fullName): Individual => $this->createIndividual->handle(
                $actor,
                $office->getKey(),
                [],
                ['full_name' => $fullName],
            ),
            $individualNames,
        );

        $companyModels = array_map(
            // tax_id is likewise absent — never set, never blanked.
            fn (array $company): Company => $this->createCompany->handle(
                $actor,
                $office->getKey(),
                [],
                ['legal_name' => $company['legal_name'], 'entity_type' => $company['entity_type']->value],
            ),
            $companies,
        );

        return [...$individuals, ...$companyModels];
    }

    /**
     * @return array<int, Project>
     */
    private function createProjects(User $actor): array
    {
        return array_map(
            fn (string $title): Project => $this->createProject->handle($actor, ['title' => $title]),
            [
                'Pekerjaan Administratif Demo 1',
                'Pekerjaan Administratif Demo 2',
                'Pekerjaan Administratif Demo 3',
            ],
        );
    }

    /**
     * @param  array<int, Project>  $projects
     * @param  array<int, Individual|Company>  $parties
     */
    private function linkProjectParties(User $actor, array $projects, array $parties): void
    {
        foreach ($projects as $index => $project) {
            $party = $parties[$index]->party;

            $this->addProjectParty->handle($actor, $project, $party, []);
        }
    }

    /**
     * @param  array<int, Project>  $projects
     * @return array<int, Matter>
     */
    private function createMatters(User $actor, array $projects): array
    {
        // service_type_id is null throughout: no canonical service catalogue
        // exists to choose a value from (CLAUDE.md — do not invent one).
        $notary1 = $this->createMatter->handle(
            $actor,
            $projects[0],
            MatterDomain::NOTARY,
            null,
            ['title' => 'Layanan Notaris Demo 1'],
        );

        $ppat1 = $this->createMatter->handle(
            $actor,
            $projects[1],
            MatterDomain::PPAT,
            null,
            ['title' => 'Layanan PPAT Demo 1'],
        );

        $notary2 = $this->createMatter->handle(
            $actor,
            $projects[2],
            MatterDomain::NOTARY,
            null,
            ['title' => 'Layanan Notaris Demo 2'],
        );

        // COMPLETED is reachable only through CompleteMatter — never assigned
        // directly. OPEN needs no further action beyond creation.
        $notary2 = $this->completeMatter->handle($actor, $notary2);

        return [$notary1, $ppat1, $notary2];
    }

    /**
     * @param  array<int, Matter>  $matters
     * @param  array<int, Individual|Company>  $parties
     */
    private function linkMatterParties(User $actor, array $matters, array $parties): void
    {
        foreach ($matters as $index => $matter) {
            // Offset so Matter participants are not identical to the Project
            // participants linked above, without needing more Parties than
            // the requested range allows.
            $party = $parties[($index + 3) % count($parties)]->party;

            $this->addMatterParty->handle($actor, $matter, $party, []);
        }
    }

    /**
     * @param  array<int, Project>  $projects
     * @param  array<int, Matter>  $matters
     * @return array<int, Document>
     */
    private function createDocuments(User $actor, array $projects, array $matters): array
    {
        // A dedicated DocumentStorage pointed at the demo-only disk — never
        // the default. UploadDocument is constructed by hand rather than
        // resolved from the container for exactly this reason: the
        // container's default DocumentStorage always resolves to the 'local'
        // disk, which this dataset must never write to.
        $uploader = new UploadDocument($this->documentAllocator, new DocumentStorage(self::DEMO_DISK), $this->events);

        $specs = [
            ['title' => 'Dokumen Administratif Demo 1', 'relations' => ['project_id' => $projects[0]->getKey()], 'verify' => false, 'archive' => false],
            ['title' => 'Dokumen Administratif Demo 2', 'relations' => ['matter_id' => $matters[0]->getKey()], 'verify' => false, 'archive' => false],
            ['title' => 'Dokumen Administratif Demo 3', 'relations' => ['project_id' => $projects[1]->getKey()], 'verify' => true, 'archive' => false],
            ['title' => 'Dokumen Administratif Demo 4', 'relations' => ['matter_id' => $matters[1]->getKey()], 'verify' => true, 'archive' => false],
            ['title' => 'Dokumen Administratif Demo 5', 'relations' => ['project_id' => $projects[2]->getKey()], 'verify' => true, 'archive' => true],
            ['title' => 'Dokumen Administratif Demo 6', 'relations' => ['matter_id' => $matters[2]->getKey()], 'verify' => true, 'archive' => true],
        ];

        $documents = [];

        foreach ($specs as $index => $spec) {
            $number = $index + 1;

            $file = UploadedFile::fake()->createWithContent(
                "dokumen-demo-{$number}.txt",
                "Berkas demo tidak sensitif nomor {$number}. Tidak ada isi hukum atau data pribadi nyata.",
            );

            $document = $uploader->handle(
                $actor,
                $file,
                ['title' => $spec['title']],
                $spec['relations'],
            );

            // RECEIVED is upload's own result — nothing further needed for it.
            if ($spec['verify']) {
                $document = $this->verifyDocument->handle($actor, $document);
            }

            if ($spec['archive']) {
                $document = $this->archiveDocument->handle($actor, $document);
            }

            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @param  array<int, Project>  $projects
     * @param  array<int, Matter>  $matters
     * @return array<int, Task>
     */
    private function createTasks(User $actor, array $projects, array $matters): array
    {
        $open1 = $this->createTask->handle($actor, ['title' => 'Tugas Administratif Demo 1'], $projects[0]);
        $open2 = $this->createTask->handle($actor, ['title' => 'Tugas Administratif Demo 2'], null, $matters[0]);

        $inProgress = $this->createTask->handle($actor, ['title' => 'Tugas Administratif Demo 3'], $projects[1]);
        $inProgress = $this->updateTask->handle($actor, $inProgress, ['status' => 'IN_PROGRESS']);

        $waiting = $this->createTask->handle($actor, ['title' => 'Tugas Administratif Demo 4'], null, $matters[1]);
        $waiting = $this->updateTask->handle($actor, $waiting, ['status' => 'WAITING']);

        $completed = $this->createTask->handle($actor, ['title' => 'Tugas Administratif Demo 5'], $projects[2]);
        $completed = $this->completeTask->handle($actor, $completed);

        $cancelled = $this->createTask->handle($actor, ['title' => 'Tugas Administratif Demo 6'], null, $matters[2]);
        $cancelled = $this->cancelTask->handle($actor, $cancelled);

        return [$open1, $open2, $inProgress, $waiting, $completed, $cancelled];
    }

    private function cleanupDemoDisk(): void
    {
        try {
            Storage::disk(self::DEMO_DISK)->deleteDirectory(DocumentStorage::ROOT);
        } catch (Throwable) {
            // Best-effort only — the exception that triggered this cleanup is
            // what the caller needs to see, not a cleanup failure replacing it.
        }
    }
}
