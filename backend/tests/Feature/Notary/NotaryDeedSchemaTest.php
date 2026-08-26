<?php

use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Notary\Enums\NotaryDeedStatus;
use App\Models\Document;
use App\Models\Matter;
use App\Models\NotaryDeed;
use App\Models\NotaryMatter;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Table shape
|--------------------------------------------------------------------------
*/

it('gives a notarial deed a generated ULID primary key', function (): void {
    $deed = NotaryDeed::factory()->create();

    expect($deed->getKeyType())->toBe('string')
        ->and($deed->getIncrementing())->toBeFalse()
        ->and(strlen($deed->id))->toBe(26)
        ->and(Str::isUlid($deed->id))->toBeTrue();
});

it('carries exactly the canonical M6.1 notary_deeds columns', function (): void {
    // Transcribed from 03_DATABASE_ERD.md section 17 with three omissions, each
    // decided rather than drifted into (D-120): `locked_by`, `deleted_at` and
    // `created_by`.
    $columns = Schema::getColumnListing('notary_deeds');
    sort($columns);

    $expected = [
        'approved_at', 'approved_by', 'created_at', 'deed_date', 'deed_number',
        'deed_type_code', 'draft_document_id', 'final_document_id', 'finalized_at',
        'finalized_by', 'id', 'locked_at', 'matter_id', 'minuta_document_id',
        'office_id', 'reviewed_at', 'reviewed_by', 'status', 'title', 'updated_at',
    ];
    sort($expected);

    expect($columns)->toBe($expected);
});

it('omits the three columns M6.0 ruled out, each for its own recorded reason', function (): void {
    // `locked_by`: the ERD carries `locked_at` alone, and adding an actor would
    // assert that somebody performs a locking act — an open domain question.
    expect(Schema::hasColumn('notary_deeds', 'locked_by'))->toBeFalse();

    // `deleted_at`: four canonical sources agree — the ERD omits it, section 33
    // prefers states over deletion for finalized legal records, CLAUDE.md section 30
    // forbids user-facing hard delete of finalized Deeds, and no
    // `notary.deeds.delete` capability exists.
    expect(Schema::hasColumn('notary_deeds', 'deleted_at'))->toBeFalse()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(NotaryDeed::class), true))->toBeFalse();

    // `created_by`: unlike Task, the OWN predicate resolves through the parent
    // Matter, so nothing structurally requires an owner column here.
    expect(Schema::hasColumn('notary_deeds', 'created_by'))->toBeFalse();
});

it('does not store the domain a second time', function (): void {
    // `matters.domain` is the one discriminator (M4.2). A copy on the deed would be
    // a second thing that can disagree with it.
    expect(Schema::hasColumn('notary_deeds', 'domain'))->toBeFalse();
});

it('carries exactly the canonical notary_matters columns plus its office carrier', function (): void {
    $columns = Schema::getColumnListing('notary_matters');
    sort($columns);

    $expected = [
        'created_at', 'deed_category', 'matter_id', 'notes', 'office_id',
        'requires_minuta', 'requires_register_entry', 'updated_at',
    ];
    sort($expected);

    expect($columns)->toBe($expected);
});

it('keys the notary matter extension by its matter, with no surrogate', function (): void {
    $extension = NotaryMatter::factory()->create();

    expect($extension->getKeyName())->toBe('matter_id')
        ->and($extension->getIncrementing())->toBeFalse()
        ->and(Schema::hasColumn('notary_matters', 'id'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The status vocabulary, and what reaches it
|--------------------------------------------------------------------------
*/

it('transcribes all six canonical deed statuses', function (): void {
    expect(NotaryDeedStatus::values())->toBe([
        'DRAFT', 'UNDER_REVIEW', 'APPROVED', 'FINALIZED', 'VOID', 'SUPERSEDED',
    ]);
});

it('separates the four reachable statuses from the two that are stored vocabulary', function (): void {
    // The D-109 pattern, and the reason M6.0 made it explicit rather than a comment:
    // VOID and SUPERSEDED are canonical values that no code path produces, because
    // the correction mechanisms that would produce them are open question five and
    // no `notary.deeds.void` capability exists.
    expect(NotaryDeedStatus::reachable())->toBe([
        NotaryDeedStatus::DRAFT,
        NotaryDeedStatus::UNDER_REVIEW,
        NotaryDeedStatus::APPROVED,
        NotaryDeedStatus::FINALIZED,
    ])->and(NotaryDeedStatus::unreachable())->toBe([
        NotaryDeedStatus::VOID,
        NotaryDeedStatus::SUPERSEDED,
    ]);

    // Together they are the whole vocabulary and they do not overlap.
    $union = array_merge(NotaryDeedStatus::reachable(), NotaryDeedStatus::unreachable());

    expect($union)->toHaveCount(count(NotaryDeedStatus::cases()));
});

it('does not name LOCKED as a status', function (): void {
    // CLAUDE.md section 29's ladder ends at LOCKED, but the ERD's status list does
    // not contain it: locking is `locked_at`, a separate column. A seventh case
    // would contradict the transcription.
    expect(NotaryDeedStatus::tryFrom('LOCKED'))->toBeNull()
        ->and(Schema::hasColumn('notary_deeds', 'locked_at'))->toBeTrue();
});

it('describes the lifecycle each status permits', function (): void {
    expect(NotaryDeedStatus::DRAFT->isReviewable())->toBeTrue()
        ->and(NotaryDeedStatus::UNDER_REVIEW->isReviewable())->toBeFalse()
        ->and(NotaryDeedStatus::UNDER_REVIEW->isApprovable())->toBeTrue()
        ->and(NotaryDeedStatus::DRAFT->isApprovable())->toBeFalse()
        ->and(NotaryDeedStatus::APPROVED->isFinalizable())->toBeTrue()
        ->and(NotaryDeedStatus::UNDER_REVIEW->isFinalizable())->toBeFalse();
});

it('keeps a deed editable up to approval and no further', function (): void {
    // The literal reading of CLAUDE.md section 29, which denies normal updates once
    // *finalized* and says nothing about approval. The narrower rule — that approval
    // freezes the content — is the more familiar one and is deliberately not encoded,
    // because no canonical document states it (section 62).
    expect(NotaryDeedStatus::DRAFT->isEditable())->toBeTrue()
        ->and(NotaryDeedStatus::UNDER_REVIEW->isEditable())->toBeTrue()
        ->and(NotaryDeedStatus::APPROVED->isEditable())->toBeTrue()
        ->and(NotaryDeedStatus::FINALIZED->isEditable())->toBeFalse()
        ->and(NotaryDeedStatus::VOID->isEditable())->toBeFalse()
        ->and(NotaryDeedStatus::SUPERSEDED->isEditable())->toBeFalse();
});

it('treats a finalized or locked deed as read-only', function (): void {
    $finalized = NotaryDeed::factory()->finalized()->create();
    $draft = NotaryDeed::factory()->create();

    expect($finalized->isReadOnly())->toBeTrue()
        ->and($draft->isReadOnly())->toBeFalse();

    // `locked_at` is written by nothing in M6, but the model honours it if a future
    // milestone ever does.
    $draft->forceFill(['locked_at' => now()])->save();

    expect($draft->fresh()->isReadOnly())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Deed numbering — the shape, without the rule
|--------------------------------------------------------------------------
*/

it('lets a deed exist without a number', function (): void {
    // No creation path allocates one. Requiring it would assert the number exists
    // before the deed does, which is half of "who assigns the number, and when?"
    $deed = NotaryDeed::factory()->create();

    expect($deed->deed_number)->toBeNull();
});

it('allows many unnumbered deeds in one office', function (): void {
    // NULLs are distinct in a unique index on both connections, so the uniqueness
    // rule does not accidentally permit only one draft per Office.
    $matter = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();

    NotaryDeed::factory()->forMatter($matter)->count(3)->create();

    expect(NotaryDeed::query()->where('office_id', $matter->office_id)->count())->toBe(3);
});

it('refuses two deeds sharing a number within one office', function (): void {
    $matter = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();

    NotaryDeed::factory()->forMatter($matter)->numbered('UJI-1')->create();

    expect(fn () => NotaryDeed::factory()->forMatter($matter)->numbered('UJI-1')->create())
        ->toThrow(QueryException::class);
});

it('lets two offices use the same number', function (): void {
    // A legal number is unique within the office that issued it, not globally.
    $first = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();
    $second = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();

    NotaryDeed::factory()->forMatter($first)->numbered('UJI-1')->create();
    NotaryDeed::factory()->forMatter($second)->numbered('UJI-1')->create();

    expect(NotaryDeed::query()->where('deed_number', 'UJI-1')->count())->toBe(2);
});

it('validates no number format anywhere in the domain', function (): void {
    // The rule CLAUDE.md section 62 names explicitly. A deed number is whatever the
    // office says it is; the software stores it.
    $matter = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();

    $deed = NotaryDeed::factory()->forMatter($matter)->numbered('apa saja / 99 / xyz')->create();

    expect($deed->fresh()->deed_number)->toBe('apa saja / 99 / xyz');
});

it('builds no deed number allocator', function (): void {
    // D-103 already ruled that the Matter allocator's N-YYYY-NNNNNN is "an
    // operational identifier, never a legal deed number". Reusing it here — or
    // building a second counter table — would be the conflation D-103 and D-108
    // exist to prevent.
    expect(Schema::hasTable('notary_deed_reference_counters'))->toBeFalse()
        ->and(Schema::hasTable('notary_register_entries'))->toBeFalse()
        ->and(class_exists('App\Domains\Notary\AllocateNotaryDeedNumber'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Office is structural
|--------------------------------------------------------------------------
*/

it('refuses a deed whose matter belongs to another office', function (): void {
    $matter = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();
    $elsewhere = Office::factory()->create();

    expect(fn () => NotaryDeed::factory()->create([
        'matter_id' => $matter->getKey(),
        'office_id' => $elsewhere->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses a deed pointing at another office document', function (): void {
    $matter = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();
    $foreign = Document::factory()->create();

    expect($foreign->office_id)->not->toBe($matter->office_id);

    expect(fn () => NotaryDeed::factory()->forMatter($matter)->create([
        'draft_document_id' => $foreign->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses a reviewer from another office', function (): void {
    $matter = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();
    $outsider = User::factory()->create();

    expect($outsider->office_id)->not->toBe($matter->office_id);

    expect(fn () => NotaryDeed::factory()->forMatter($matter)->create([
        'reviewed_at' => now(),
        'reviewed_by' => $outsider->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses to delete a document a deed depends on', function (): void {
    $matter = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();
    $document = Document::factory()->create(['office_id' => $matter->office_id]);

    NotaryDeed::factory()->forMatter($matter)->create([
        'final_document_id' => $document->getKey(),
    ]);

    // RESTRICT, not cascade: a deed's evidence does not vanish because somebody
    // tidied a document list. `forceDelete` because Document soft-deletes.
    expect(fn () => $document->forceDelete())->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Model guards
|--------------------------------------------------------------------------
*/

it('refuses to move a deed between offices', function (): void {
    $deed = NotaryDeed::factory()->create();
    $elsewhere = Office::factory()->create();

    expect(fn () => $deed->forceFill(['office_id' => $elsewhere->getKey()])->save())
        ->toThrow(RuntimeException::class, 'immutable');
});

it('refuses to move a deed between matters', function (): void {
    $deed = NotaryDeed::factory()->create();
    $other = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();

    expect(fn () => $deed->forceFill(['matter_id' => $other->getKey()])->save())
        ->toThrow(RuntimeException::class, 'immutable');
});

it('refuses half of a recorded act', function (string $act): void {
    // A PostgreSQL CHECK enforces this too; the model guard is what holds it on the
    // SQLite connection the suite runs on.
    $deed = NotaryDeed::factory()->create();

    expect(fn () => $deed->forceFill(["{$act}_at" => now()])->save())
        ->toThrow(RuntimeException::class, 'pair');
})->with(['reviewed', 'approved', 'finalized']);

it('keeps status, the number and the act pairs out of mass assignment', function (): void {
    // Each answers to its own capability. Letting `fill()` reach any of them would
    // make `notary.deeds.update` a silent superset of four other codes (D-091).
    $deed = NotaryDeed::factory()->create();

    $deed->fill([
        'status' => NotaryDeedStatus::FINALIZED->value,
        'deed_number' => 'UJI-9',
        'approved_at' => now(),
        'approved_by' => User::factory()->create()->getKey(),
        'locked_at' => now(),
    ]);

    expect($deed->status)->toBe(NotaryDeedStatus::DRAFT)
        ->and($deed->deed_number)->toBeNull()
        ->and($deed->approved_at)->toBeNull()
        ->and($deed->locked_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Relations
|--------------------------------------------------------------------------
*/

it('reaches its matter, documents and actors', function (): void {
    $matter = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();
    $document = Document::factory()->create(['office_id' => $matter->office_id]);
    $actor = User::factory()->create(['office_id' => $matter->office_id]);

    $deed = NotaryDeed::factory()->forMatter($matter)->create([
        'draft_document_id' => $document->getKey(),
        'reviewed_at' => now(),
        'reviewed_by' => $actor->getKey(),
    ]);

    expect($deed->matter->is($matter))->toBeTrue()
        ->and($deed->draftDocument->is($document))->toBeTrue()
        ->and($deed->reviewer->is($actor))->toBeTrue()
        ->and($deed->office->getKey())->toBe($matter->office_id);
});

it('reaches its deeds and extension from the matter', function (): void {
    $matter = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();
    NotaryMatter::factory()->forMatter($matter)->create();
    NotaryDeed::factory()->forMatter($matter)->count(2)->create();

    expect($matter->notaryDeeds()->count())->toBe(2)
        ->and($matter->notaryExtension)->not->toBeNull()
        ->and($matter->notaryExtension->requires_minuta)->toBeTrue();
});

it('refuses a second extension row for one matter', function (): void {
    // `matter_id` is the primary key, so the cardinality is structural rather than
    // validated.
    $matter = Matter::factory()->state(['domain' => MatterDomain::NOTARY])->create();
    NotaryMatter::factory()->forMatter($matter)->create();

    expect(fn () => NotaryMatter::factory()->forMatter($matter)->create())
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| What M6.1 deliberately did not build
|--------------------------------------------------------------------------
*/

it('registers no new permission', function (): void {
    // Every Notary code M6 implements has been canonical since M1.2. The M6 brief
    // asked for roughly 22 new codes and a total of 199; the count stays at 177.
    expect(app(PermissionRegistry::class)->all())->toHaveCount(177);
});

it('adds no code for an act nobody has documented', function (string $code): void {
    // M6.0 verified these absent against the live registry, and M6.1 builds no act
    // that has no canonical code. Three of them are the post-finalization correction
    // mechanisms `08_NOTARY_WORKFLOW.md` section 6 asks about.
    expect(app(PermissionRegistry::class)->all())->not->toContain($code);
})->with([
    'notary.deeds.lock',
    'notary.deeds.void',
    'notary.deeds.delete',
    'notary.register.delete',
    'notary.minuta.delete',
    'notary.protocol.view',
    'notary.protocol.create',
    'notary.protocol.update',
    'notary.protocol.close',
]);

it('honours notary.deeds.number as the separate capability the catalogue defined', function (): void {
    // It exists, and nothing in the repository had used it before M6. Folding
    // numbering into finalization would assert that a deed is numbered when it is
    // finalized — half of open question one.
    expect(app(PermissionRegistry::class)->all())->toContain('notary.deeds.number');
});

it('creates no register or protocol table', function (string $table): void {
    // **Narrowed at M6.3, not deleted.** `notary_minuta` was on this list while it was
    // unbuilt; M6.3 owns it now and `NotaryMinutaTest` asserts its shape.
    //
    // What survives is the claim that outlives this milestone: registers and protocol
    // are **batch 11** per 03_DATABASE_ERD.md section 32 — later even than PPAT
    // deeds — and are outside M6 entirely (D-120). The brief's `notary_protocols` and
    // `notary_protocol_items` are not canonical at all: the ERD's table is
    // `protocol_records`, one table with a domain discriminator and no junction to
    // deeds (O-036).
    expect(Schema::hasTable($table))->toBeFalse();
})->with([
    'notary_register_entries',
    'notary_protocols',
    'notary_protocol_items',
    'protocol_records',
]);

it('does not build the document junction it just unblocked', function (): void {
    // D-118 recorded `notary_deed_documents` as blocked because `notary_deeds` did
    // not exist — structural, not a scoping preference. M6.1 removes the obstacle
    // and leaves the surface to whoever wants it.
    expect(Schema::hasTable('notary_deed_documents'))->toBeFalse();
});

it('improvises no Notary-specific audit store', function (string $table): void {
    // D-115 and D-118 both refused this, and M5.4 refused it again. `reviewed_by`,
    // `approved_by` and `finalized_by` record who and when on the row itself.
    //
    // **Narrowed at M8.1, not deleted.** `audit_logs` and `activities` were in
    // this list because M6 had to build neither; M8.1 builds both from the
    // canonical ERD field lists under D-123, so their absence is no longer the
    // assertion. A Notary-shaped audit table would still be the improvisation
    // D-115 refused.
    expect(Schema::hasTable($table))->toBeFalse();
})->with(['notary_deed_activities', 'notary_audit_logs']);
