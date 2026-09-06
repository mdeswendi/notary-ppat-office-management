<?php

namespace App\Domains\Demo;

use App\Console\Commands\DemoDataSeedCommand;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Authorization\Actions\ReplaceUserRoles;
use App\Domains\Authorization\DefaultRoleRegistry;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Demo\Exceptions\DemoDatasetAlreadyExists;
use App\Domains\Demo\Exceptions\DemoRolePrerequisiteMissing;
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
use App\Domains\Notary\Actions\ApproveNotaryDeed;
use App\Domains\Notary\Actions\CreateNotaryDeed;
use App\Domains\Notary\Actions\FileMinuta;
use App\Domains\Notary\Actions\FinalizeNotaryDeed;
use App\Domains\Notary\Actions\ReviewNotaryDeed;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
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
 * creates use — this class supplies no number itself, anywhere.
 * `notary_deeds.deed_number` is no exception: {@see createNotaryDeeds()}
 * never calls `RecordNotaryDeedNumber`, so it stays `null` on every deed this
 * class creates, including the `FINALIZED` one (D-120 permits this
 * explicitly). Warkah, Property, Workflow, PPAT Deed, Quotation, Invoice and
 * Payment entities remain entirely out of scope for this dataset; see the
 * class this ships alongside, `DemoDataSeedCommand`, for what is and is not
 * built.
 *
 * **Organization and Office are the one exception to "through an Action."**
 * No `CreateOrganization` or `CreateOffice` Action exists anywhere in this
 * codebase — there is nothing to call. `BootstrapDeploymentCommand` (D-034)
 * constructs both directly with `new Organization`/`new Office`, and
 * {@see build()} follows that exact precedent rather than inventing a second
 * pattern for one dataset. A test asserts direct construction of either model
 * appears nowhere else in `app/`.
 *
 * **Exactly one demo user is made authorization-capable, not five.** After
 * {@see createUsers()} returns, {@see makeActorAuthorizationCapable()} assigns
 * the canonical `SUPER_ADMIN` role — and only that role, to only the first of
 * the five users — then proves the assignment actually works by calling the
 * same Policy classes a real Controller would. It never creates a Role or
 * grants a permission; see that method's docblock for what happens when
 * neither is in place yet.
 *
 * **The primary actor is also the only one who can be logged into.** {@see
 * seed()} takes that actor's password as an explicit parameter — this class
 * never prompts, reads console input, or reads config/environment for it;
 * {@see DemoDataSeedCommand} is the one place that
 * collects and validates it, the same separation the guard already has (see
 * above). The other four users keep the unknown, unprinted `Str::random(32)`
 * password this dataset has always given them — see {@see createUsers()}.
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
     * The one demo user {@see makeActorAuthorizationCapable()} assigns
     * `SUPER_ADMIN` to, and the one whose password {@see seed()} accepts as
     * a parameter rather than generating. Named here once so the command's
     * prompt text and this class's user list can never name two different
     * accounts by accident.
     */
    public const PRIMARY_ACTOR_EMAIL = 'notaris.demo@example.test';

    /**
     * Never `local`. Demo documents must not be reachable through, or mixed
     * with, the disk a real Document's bytes live on.
     */
    public const DEMO_DISK = 'local_demo';

    /**
     * A real, minimal, hand-built PDF committed to the repository — see
     * {@see createNotaryMinuta()}. Relative to `base_path()` (the `backend/`
     * root), never an absolute path this class stores or exposes anywhere.
     */
    private const MINUTA_ASSET_PATH = 'resources/demo/minuta-demo.pdf';

    public function __construct(
        private readonly CreateUser $createUser,
        private readonly ReplaceUserRoles $replaceUserRoles,
        private readonly IndividualPolicy $individualPolicy,
        private readonly CompanyPolicy $companyPolicy,
        private readonly ProjectPolicy $projectPolicy,
        private readonly MatterPolicy $matterPolicy,
        private readonly DocumentPolicy $documentPolicy,
        private readonly TaskPolicy $taskPolicy,
        private readonly NotaryDeedPolicy $notaryDeedPolicy,
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
        private readonly CreateNotaryDeed $createNotaryDeed,
        private readonly ReviewNotaryDeed $reviewNotaryDeed,
        private readonly ApproveNotaryDeed $approveNotaryDeed,
        private readonly FinalizeNotaryDeed $finalizeNotaryDeed,
        private readonly FileMinuta $fileMinuta,
    ) {}

    /**
     * Whether the marker Office already exists — the same check {@see seed()}
     * makes internally, exposed so a caller (`DemoDataSeedCommand`) can avoid
     * doing anything else, including prompting for a password, when a dataset
     * is already there. Read-only: nothing is written by calling this.
     */
    public function alreadySeeded(): bool
    {
        return Office::query()->where('code', self::OFFICE_CODE)->exists();
    }

    /**
     * @param  string  $primaryActorPassword  Plaintext, held only for the
     *                                        duration of this call and the
     *                                        one `CreateUser::handle()` call
     *                                        it is passed to, which hashes it
     *                                        immediately via the model's
     *                                        `hashed` cast. Never logged,
     *                                        never echoed, never written to
     *                                        {@see DemoSeedResult}.
     *
     * @throws DemoDatasetAlreadyExists when the marker Office already exists —
     *                                  nothing is read or written beyond that
     *                                  one check
     */
    public function seed(string $primaryActorPassword): DemoSeedResult
    {
        if ($this->alreadySeeded()) {
            throw DemoDatasetAlreadyExists::markedBy(self::OFFICE_CODE);
        }

        try {
            return DB::transaction(fn (): DemoSeedResult => $this->build($primaryActorPassword));
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

    private function build(string $primaryActorPassword): DemoSeedResult
    {
        // Direct model construction, deliberately — see the class docblock.
        // `BootstrapDeploymentCommand::handle()` (app/Console/Commands/
        // BootstrapDeploymentCommand.php) is the only other place in this
        // codebase that builds an Organization or an Office at all, and it
        // does so the same way, for the same reason: neither model has a
        // `Create*` Action to call.
        $organization = new Organization;
        $organization->name = self::ORGANIZATION_NAME;
        $organization->save();

        $office = new Office;
        $office->organization_id = $organization->getKey();
        $office->code = self::OFFICE_CODE;
        $office->name = 'Kantor Notaris & PPAT Demo — Kantor Pusat';
        $office->save();

        $users = $this->createUsers($office, $primaryActorPassword);
        $actor = $users[0];

        $this->makeActorAuthorizationCapable($actor);

        $parties = $this->createParties($actor, $office);

        $projects = $this->createProjects($actor);
        $this->linkProjectParties($actor, $projects, $parties);

        $matters = $this->createMatters($actor, $projects);
        $this->linkMatterParties($actor, $matters, $parties);

        $documents = $this->createDocuments($actor, $projects, $matters);
        $notaryDeeds = $this->createNotaryDeeds($actor, $matters, $documents);
        $notaryMinuta = $this->createNotaryMinuta($actor, $notaryDeeds);
        $tasks = $this->createTasks($actor, $projects, $matters, $users);

        return new DemoSeedResult(
            officeCode: self::OFFICE_CODE,
            users: count($users),
            parties: count($parties),
            projects: count($projects),
            matters: count($matters),
            // The Minuta's own filing Document is not part of $documents —
            // see createNotaryMinuta() — so it is counted here explicitly
            // rather than silently missing from what this dataset reports.
            documents: count($documents) + 1,
            tasks: count($tasks),
            notaryDeeds: count($notaryDeeds),
            notaryMinutas: 1,
        );
    }

    /**
     * @return array<int, User>
     */
    private function createUsers(Office $office, string $primaryActorPassword): array
    {
        // CreateUser itself grants no role to any of these five (see its own
        // docblock) — none of the Actions this class calls checks a
        // permission or a role internally, every one authorizes nothing
        // itself and trusts that its caller already did (CLAUDE.md §35).
        // makeActorAuthorizationCapable() assigns a role to the first of
        // these five separately, right after this method returns — a
        // deliberately distinct step, since "was this User created" and "can
        // this User pass an authorization check" are different questions.
        //
        // Only the first (self::PRIMARY_ACTOR_EMAIL) gets the operator-chosen
        // password `seed()` was called with — hashed immediately by the
        // model's `hashed` cast, same as any other user creation. The other
        // four keep `Str::random(32)`: generated, never printed, logged, or
        // otherwise recoverable, exactly as before — nothing about this
        // dataset needs more than one account anyone can actually sign into.
        $people = [
            ['name' => 'Notaris Demo', 'email' => self::PRIMARY_ACTOR_EMAIL, 'password' => $primaryActorPassword],
            ['name' => 'PPAT Staff Demo', 'email' => 'ppat.staff.demo@example.test', 'password' => null],
            ['name' => 'Staf Administrasi Demo', 'email' => 'admin.staff.demo@example.test', 'password' => null],
            ['name' => 'Petugas Arsip Demo', 'email' => 'arsip.demo@example.test', 'password' => null],
            ['name' => 'Front Office Demo', 'email' => 'frontoffice.demo@example.test', 'password' => null],
        ];

        return array_map(
            fn (array $person): User => $this->createUser->handle([
                'name' => $person['name'],
                'email' => $person['email'],
                'phone' => null,
                'office_id' => $office->getKey(),
                // A `null` here means "not the primary actor" — fall back to
                // a fresh random password per run, never reused, never
                // logged, never printed. A fixed or guessable password would
                // be a credential left in source for no reason these four
                // need.
                'password' => $person['password'] ?? Str::random(32),
            ]),
            $people,
        );
    }

    /**
     * Assigns the canonical `SUPER_ADMIN` role to the primary demo actor —
     * and only to that one user — then proves the assignment is not merely
     * cosmetic by calling the exact Policy classes a real Controller would:
     * {@see IndividualPolicy}, {@see CompanyPolicy}, {@see ProjectPolicy},
     * {@see MatterPolicy} for both domains, {@see DocumentPolicy},
     * {@see TaskPolicy}, and {@see NotaryDeedPolicy}.
     *
     * **Never creates a Role, never grants a permission.** Both come only
     * from `permissions:sync` and `app:bootstrap` (or equivalent manual Role
     * Management configuration) — deliberately outside this class's reach
     * (`CLAUDE.md` §24; D-045, D-057 forbid inventing authorization state
     * silently). `SUPER_ADMIN` is the one canonical role D-057 guarantees
     * holds every permission at `ALL` scope the moment a deployment has been
     * bootstrapped — the other eight default roles are created empty and
     * configured by hand, so none of them is a reliable target here.
     *
     * If the role does not exist yet, or exists but cannot pass one of the
     * checks below, this throws {@see DemoRolePrerequisiteMissing} rather
     * than fabricating the missing configuration — the caller's transaction
     * rolls every write in this run back, exactly as any other mid-run
     * failure does.
     *
     * This method itself still says nothing about whether anyone can
     * *authenticate* as this actor — authorization readiness and login
     * readiness are different questions, and this one answers only the
     * first. Login readiness for the same actor is what {@see seed()}'s
     * `$primaryActorPassword` parameter is for; see {@see createUsers()}.
     */
    private function makeActorAuthorizationCapable(User $actor): void
    {
        $role = Role::query()
            ->where('name', DefaultRoleRegistry::ADMINISTRATOR)
            ->where('guard_name', PermissionRegistry::GUARD)
            ->first();

        if ($role === null) {
            throw DemoRolePrerequisiteMissing::roleNotFound(DefaultRoleRegistry::ADMINISTRATOR);
        }

        $this->replaceUserRoles->handle($actor, [$role]);

        $surfaces = [
            'Parties (Individual)' => fn (): bool => $this->individualPolicy->viewAny($actor),
            'Parties (Company)' => fn (): bool => $this->companyPolicy->viewAny($actor),
            'Projects' => fn (): bool => $this->projectPolicy->viewAny($actor),
            'Notary Matters' => fn (): bool => $this->matterPolicy->viewAny($actor, MatterDomain::NOTARY),
            'PPAT Matters' => fn (): bool => $this->matterPolicy->viewAny($actor, MatterDomain::PPAT),
            'Documents' => fn (): bool => $this->documentPolicy->viewAny($actor),
            'Tasks' => fn (): bool => $this->taskPolicy->viewAny($actor),
            'Notary Deeds' => fn (): bool => $this->notaryDeedPolicy->viewAny($actor),
        ];

        foreach ($surfaces as $surface => $isReachable) {
            if (! $isReachable()) {
                throw DemoRolePrerequisiteMissing::policyUnreachable(DefaultRoleRegistry::ADMINISTRATOR, $surface);
            }
        }
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
     * Four Notarial Deeds, distributed across the two NOTARY Matters {@see
     * createMatters()} already built — never a Matter created for this
     * purpose alone, since `Matter::notaryDeeds()` is an official `hasMany`
     * relation and nothing about the domain caps one Matter to one Deed.
     *
     * Entirely through the same four lifecycle Actions a real Controller
     * calls ({@see CreateNotaryDeed}, {@see ReviewNotaryDeed}, {@see
     * ApproveNotaryDeed}, {@see FinalizeNotaryDeed}) — never `RecordNotaryDeedNumber`,
     * so `deed_number` stays `null` on every one of the four, exactly as a
     * deed with no number assigned yet already is (D-120: numbering is its
     * own capability, never implied by any lifecycle act, and a deed may be
     * `FINALIZED` with none). `deed_type_code` and `deed_date` are likewise
     * never set: M6 seeds no deed-type catalogue at all — `NotaryDeedFactory`
     * and `NotaryDeedManagementTest` both leave `deed_type_code` `null` for
     * the same reason — and a fabricated execution date would be exactly the
     * invented legal fact `CLAUDE.md` §62 forbids.
     *
     * One reachable status each — `DRAFT`, `UNDER_REVIEW`, `APPROVED`,
     * `FINALIZED` — simply by stopping the lifecycle calls at a different
     * point per deed. `VOID` and `SUPERSEDED` are not attempted: no Action
     * produces either (D-120).
     *
     * **The fourth deed's `final_document_id` is set at creation, not after.**
     * `UpdateNotaryDeed` — the only other Action that could reach that
     * column — refuses once a deed is `FINALIZED` (`isEditable()` is false),
     * so attaching the Document has to happen while the attributes are still
     * writable. `final_document_id` is a fillable column `CreateNotaryDeed`
     * already accepts via its `$attributes` array — precisely what
     * `StoreNotaryDeedRequest::deedAttributes()` forwards from a real
     * request — not a pivot and not a direct column write. The Document
     * chosen ({@see createDocuments()}'s sixth, already linked to the same
     * Matter) satisfies the composite foreign key requiring the Document and
     * the Deed to share one Office.
     *
     * @param  array<int, Matter>  $matters
     * @param  array<int, Document>  $documents
     * @return array<int, NotaryDeed>
     */
    private function createNotaryDeeds(User $actor, array $matters, array $documents): array
    {
        $notary1 = $matters[0];
        $notary2 = $matters[2];

        $draft = $this->createNotaryDeed->handle($actor, $notary1, ['title' => 'Akta Notaris Demo 1']);

        $underReview = $this->createNotaryDeed->handle($actor, $notary1, ['title' => 'Akta Notaris Demo 2']);
        $underReview = $this->reviewNotaryDeed->handle($actor, $underReview);

        $approved = $this->createNotaryDeed->handle($actor, $notary2, ['title' => 'Akta Notaris Demo 3']);
        $approved = $this->reviewNotaryDeed->handle($actor, $approved);
        $approved = $this->approveNotaryDeed->handle($actor, $approved);

        $finalized = $this->createNotaryDeed->handle($actor, $notary2, [
            'title' => 'Akta Notaris Demo 4',
            'final_document_id' => $documents[5]->getKey(),
        ]);
        $finalized = $this->reviewNotaryDeed->handle($actor, $finalized);
        $finalized = $this->approveNotaryDeed->handle($actor, $finalized);
        $finalized = $this->finalizeNotaryDeed->handle($actor, $finalized);

        return [$draft, $underReview, $approved, $finalized];
    }

    /**
     * One Minuta Akta, filed against the FINALIZED deed — "Akta Notaris Demo
     * 4", the fourth element {@see createNotaryDeeds()} returns — so the
     * Notary Deed Detail screenshot's Minuta section shows a real filing
     * instead of the "nothing filed yet" prompt (Task 3C).
     *
     * **Through the same two production Actions a real Controller calls**:
     * {@see UploadDocument} to file the record the Minuta points at — a
     * Minuta's `document_id` names a Document that already exists
     * ({@see NotaryMinutaController::store()} resolves one from the request
     * rather than accepting an upload directly), never a new upload endpoint
     * of its own — then {@see FileMinuta} itself.
     *
     * **A Minuta may be filed against a deed in any status** — `FileMinuta`
     * requires none, per its own docblock ("when an office files the
     * original" is an open domain question this class does not answer
     * either). Filing does not touch the deed's own `status` or
     * `is_read_only` at all.
     *
     * **The uploaded file is a real, hand-built, minimal PDF**
     * (`resources/demo/minuta-demo.pdf`, self::MINUTA_ASSET_PATH) — not
     * `UploadedFile::fake()`, which this class uses for the six ordinary
     * demo Documents but was asked not to reuse here. `getMimeType()` reads
     * the file's actual bytes (confirmed `application/pdf`, not inferred
     * from the name), so this is never plain text wearing a `.pdf`
     * extension. The asset carries no personal data and no `/Info`
     * dictionary of any kind — just three lines of plain body text stating
     * it is a demo file.
     *
     * `new UploadedFile(...)` — the real constructor, not the `::fake()`
     * factory — is the documented way to wrap a file that already exists on
     * disk as an `UploadedFile` instance outside an HTTP request; `$test:
     * true` only tells Symfony's base class to skip the `is_uploaded_file()`
     * check that a real HTTP upload would otherwise satisfy on its own.
     *
     * **No shelf metadata is invented.** `archive_location`, `volume_number`
     * and `bundle_number` describe a physical filing cabinet no canonical
     * document specifies for this office, so all three are left `null` —
     * `FileMinuta`'s own default for an empty `$attributes` array.
     *
     * **The primary actor's `notary.minuta.create` reach is verified here**,
     * against the one deed this method actually uses — `viewAny()`-style
     * verification isn't possible earlier in {@see
     * makeActorAuthorizationCapable()} because every `NotaryDeedPolicy`
     * ability beyond `viewAny()` takes a record, and no deed exists yet at
     * that point in {@see build()}.
     *
     * @param  array<int, NotaryDeed>  $notaryDeeds  exactly {@see
     *                                               createNotaryDeeds()}'s
     *                                               return shape: [DRAFT,
     *                                               UNDER_REVIEW, APPROVED,
     *                                               FINALIZED]
     *
     * @throws DemoRolePrerequisiteMissing if the primary actor cannot reach
     *                                     `notary.minuta.create` on this deed
     */
    private function createNotaryMinuta(User $actor, array $notaryDeeds): NotaryMinuta
    {
        $finalizedDeed = $notaryDeeds[3];

        if (! $this->notaryDeedPolicy->createMinuta($actor, $finalizedDeed)) {
            throw DemoRolePrerequisiteMissing::policyUnreachable(DefaultRoleRegistry::ADMINISTRATOR, 'Notary Minuta');
        }

        $uploader = new UploadDocument($this->documentAllocator, new DocumentStorage(self::DEMO_DISK), $this->events);

        $file = new UploadedFile(
            base_path(self::MINUTA_ASSET_PATH),
            'minuta-demo.pdf',
            'application/pdf',
            null,
            true,
        );

        $document = $uploader->handle(
            $actor,
            $file,
            ['title' => 'Minuta Akta Demo 4'],
            ['matter_id' => $finalizedDeed->matter_id],
        );

        return $this->fileMinuta->handle($actor, $finalizedDeed, $document, []);
    }

    /**
     * Six Tasks, one per canonical status with `OPEN` repeated, and —
     * unlike before — every one **assigned at creation** (Task 3B).
     *
     * **Why this changed.** `DashboardAggregator::tasks()` ("My Tasks") reads
     * only rows where `assigned_to` is the signed-in actor, and
     * `::workload()` sums `task_count`/`matter_count` per user and drops
     * anyone whose total is zero. Every Task this class created before Task
     * 3B left `assigned_to` `null` — nobody's own, on purpose, but the
     * accidental effect was that both panels rendered their empty state
     * regardless of how many Tasks existed. `CreateTask`'s own `$assignee`
     * parameter is the fix: it is the same parameter a real Controller
     * already resolves and passes, so assigning here is not a new capability
     * this class reaches for, only a use of one it always had available.
     *
     * **Three of the primary actor's Tasks are also given `due_at`, pinned to
     * calendar boundaries rather than an offset from the exact second `Date::
     * now()` returns** — a fix over this method's first version, which used
     * `now() + 2 hours` for "today" and broke whenever `demo:seed` ran after
     * ~22:00: two hours later rolls onto tomorrow's calendar date, so
     * `whereDate(due_at, today)` stopped matching and the "today" bucket went
     * empty depending on the clock at the moment the operator happened to run
     * the command. The three due dates now are:
     *
     *   - **overdue** — yesterday's calendar date at a fixed hour
     *     (`subDay()->setTime(8, 0)`). Always strictly before `now`, at every
     *     hour `now` itself could be, including just after midnight.
     *   - **today** — the last instant of today (`endOfDay()`,
     *     `23:59:59.999999`). Always still ahead of `now` — including when
     *     `demo:seed` runs at 23:30 — and always on today's calendar date, so
     *     `whereDate(due_at, today)` matches regardless of what time it is
     *     when the command runs.
     *   - **upcoming** — three calendar days ahead at a fixed hour
     *     (`addDays(3)->setTime(8, 0)`). However late or early `now` is today,
     *     three full days ahead always lands inside the aggregator's `[now,
     *     now + 7 days]` window.
     *
     * **The "today" Task legitimately also appears in "upcoming"**: the
     * aggregator's upcoming query is `whereBetween(due_at, [now, now + 7
     * days])`, and the last instant of today is later than `now` by
     * definition (right up to the moment `now` itself becomes that instant),
     * so it satisfies both queries at once, at any hour. That overlap is not
     * worked around here — the official Dashboard test accepts it (`upcoming`
     * count 2, not 1, for the identical three-Task shape), so reproducing it
     * is reproducing shipped behaviour, not a bug. What changed is only that
     * the "today" Task no longer *disappears* from its own bucket depending
     * on the clock.
     *
     * Every offset is built from `{@see Date::now()}->copy()`, never `$now`
     * itself, so chaining a mutating call (`subDay()`, `addDays()`,
     * `setTime()`, `endOfDay()`) on the result can never retroactively change
     * `$now` or any other Task's already-computed due date.
     *
     * **Only three of the four supporting users become assignees, and only
     * one of those three ever shows up in Workload.** A `COMPLETED` and a
     * `CANCELLED` Task are handed to two of them so every canonical status
     * stays represented, but `TaskStatus::openValues()` excludes both from
     * every Dashboard task/workload query — by the aggregator's own
     * definition, settled work is not "load," so those two users
     * legitimately do not appear as Workload rows. The fourth supporting
     * user gets an ordinary `OPEN` Task instead, specifically so Workload has
     * a second row beyond the primary actor. Six Tasks cannot stretch
     * further than that while still covering five statuses and three due-date
     * buckets: a third Workload row would need a fourth active-status Task,
     * and the two settled ones are not optional (D-119's ladder must stay
     * represented). This is a recorded trade-off, not an oversight.
     *
     * **`Matter.pic_user_id` is deliberately left untouched** (still `null`,
     * per {@see createMatters()}). `workload()`'s exclusion is `$tasks === 0
     * && $matters === 0`; giving the primary actor and the fourth supporting
     * user a Task each already clears that bar without reaching for a second
     * Action this dataset has no other reason to call.
     *
     * @param  array<int, Project>  $projects
     * @param  array<int, Matter>  $matters
     * @param  array<int, User>  $users
     * @return array<int, Task>
     */
    private function createTasks(User $actor, array $projects, array $matters, array $users): array
    {
        $now = Date::now();

        $today = $this->createTask->handle(
            $actor,
            ['title' => 'Tugas Administratif Demo 1', 'due_at' => $now->copy()->endOfDay()],
            $projects[0],
            null,
            $actor,
        );

        $overdue = $this->createTask->handle(
            $actor,
            ['title' => 'Tugas Administratif Demo 2', 'due_at' => $now->copy()->subDay()->setTime(8, 0)],
            null,
            $matters[0],
            $actor,
        );
        $overdue = $this->updateTask->handle($actor, $overdue, ['status' => 'IN_PROGRESS']);

        $upcoming = $this->createTask->handle(
            $actor,
            ['title' => 'Tugas Administratif Demo 3', 'due_at' => $now->copy()->addDays(3)->setTime(8, 0)],
            $projects[1],
            null,
            $actor,
        );
        $upcoming = $this->updateTask->handle($actor, $upcoming, ['status' => 'WAITING']);

        // Excluded from every Dashboard task/workload query once settled —
        // see the method docblock. due_at is irrelevant to a COMPLETED Task
        // for the same reason, so none is set.
        $completed = $this->createTask->handle(
            $actor,
            ['title' => 'Tugas Administratif Demo 4'],
            $projects[2],
            null,
            $users[1],
        );
        $completed = $this->completeTask->handle($actor, $completed);

        $cancelled = $this->createTask->handle(
            $actor,
            ['title' => 'Tugas Administratif Demo 5'],
            null,
            $matters[2],
            $users[2],
        );
        $cancelled = $this->cancelTask->handle($actor, $cancelled);

        // The second Workload row: an ordinary OPEN Task for the one
        // supporting user whose work stays "live" rather than settled.
        $openSupporting = $this->createTask->handle(
            $actor,
            ['title' => 'Tugas Administratif Demo 6', 'due_at' => $now->copy()->addDays(5)->setTime(8, 0)],
            null,
            $matters[0],
            $users[3],
        );

        return [$today, $overdue, $upcoming, $completed, $cancelled, $openSupporting];
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
