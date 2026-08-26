<?php

namespace App\Domains\Document\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Document\AllocateDocumentReference;
use App\Domains\Document\DocumentStorage;
use App\Domains\Document\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\MatterDocument;
use App\Models\PartyDocument;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * File a Document and its first version (M5.2, D-117).
 *
 * **Six fields are decided here and cannot be requested**, following
 * `CreateMatter` exactly. The caller supplies ordinary metadata; the system
 * supplies everything that would otherwise be a way around a boundary:
 *
 *   office_id         the actor's own Office, never a choice (D-116)
 *   document_number   allocated by the M5.1 allocator, never supplied
 *   status            RECEIVED
 *   current_version_id  the version this action just wrote
 *   created_by        the actor
 *   updated_by        the actor
 *
 * **Office is the actor's own, not inherited.** This is where a Document differs
 * from a Matter: a Matter lands in its Project's Office because the parent
 * already answers the question, while a Document is filed by a person and lands
 * where that person works. `ALL` does not change it — reach over existing records
 * is not authority to decide where a new one belongs (D-097, D-098, D-107).
 *
 * **`RECEIVED`, not `DRAFT`** (D-117). M5.1 created `DRAFT` because nothing could
 * move a Document anywhere; M5.2 ships verification, and verification requires
 * `RECEIVED` or `UNDER_REVIEW`. Had upload kept creating `DRAFT`, the verify
 * endpoint would have answered 422 to every document that exists.
 *
 * ## The filesystem is not transactional, and this is where that is handled
 *
 * The database work runs in one transaction. The file write cannot join it, so a
 * failure after the bytes land would leave an orphan — a file nothing references,
 * which is the mirror of the failure {@see DocumentStorage::store()} refuses to
 * create. The path is tracked and removed on the way out.
 *
 * **The orphan direction that is left alone is the safe one.** If cleanup itself
 * fails, the original exception still propagates: a stray file on a private disk
 * costs storage, while swallowing the real error would cost the caller an
 * explanation.
 *
 * This is the one case `DocumentStorage::delete()` exists for. It is **not** a
 * deletion path for a live document — `CLAUDE.md` section 19 forbids overwriting
 * a version and section 30 prefers archiving over removal.
 *
 * ## Attachments
 *
 * The three related records were already resolved and authorized by the caller;
 * their ids arrive here as ids. `office_id` on each junction row is written **from
 * the Document**, never from the request, and the composite foreign keys then
 * check it against both endpoints — so a cross-office attachment is refused by the
 * database even if every check above it were wrong.
 *
 * The Policy judged the actor before this ran. This action does not re-decide
 * authorization; it records who acted.
 */
class UploadDocument
{
    public function __construct(
        private readonly AllocateDocumentReference $allocator,
        private readonly DocumentStorage $storage,
        private readonly EventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  ordinary metadata only
     * @param  array{party_id?: string|null, project_id?: string|null, matter_id?: string|null}  $relations
     *                                                                                                       already resolved and authorized by the caller
     */
    public function handle(
        User $actor,
        UploadedFile $file,
        array $attributes,
        array $relations = [],
    ): Document {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($actor, $file, $attributes, $relations, &$storedPath): Document {
                $document = new Document;

                // None of these is fillable, by design. Assigning them explicitly
                // is the point: a reader sees every system-controlled field in one
                // place rather than inferring it from what the Request omitted.
                $document->office_id = $actor->office_id;
                $document->document_number = $this->allocator->forOffice($actor->office_id);
                $document->status = DocumentStatus::RECEIVED;
                $document->created_by = $actor->getKey();
                $document->updated_by = $actor->getKey();

                // Set from validated input rather than filled, because the flag
                // decides which capability a later download answers to and must
                // never arrive by accident. Absent means false — D-115 requires it
                // be chosen, never inferred from `document_type_code`.
                $document->is_sensitive = (bool) ($attributes['is_sensitive'] ?? false);
                unset($attributes['is_sensitive']);

                $document->fill($attributes);
                $document->save();

                // After the row exists, so the path carries a real Office and the
                // version has a document to belong to.
                $metadata = $this->storage->store($file, $document);
                $storedPath = $metadata['storage_path'];

                $version = new DocumentVersion;
                $version->document_id = $document->getKey();
                $version->version_number = 1;
                $version->uploaded_by = $actor->getKey();
                $version->uploaded_at = Date::now();
                $version->fill($metadata);
                $version->save();

                // Second save on purpose: the composite foreign key — and the
                // model guard that holds the same rule on SQLite — require the
                // version to exist before anything may point at it.
                $document->current_version_id = $version->getKey();
                $document->save();

                $this->attach($actor, $document, $relations);

                // The metadata is recorded; the file never is, and neither is
                // the original filename, which for a KTP scan is often the
                // subject's own name (D-105).
                $this->events->created($document, $actor, ActivityType::DOCUMENT_UPLOADED, [
                    'reference' => $document->document_number,
                    'title' => $document->title,
                ]);

                return $document;
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                try {
                    $this->storage->delete(DocumentStorage::DISK, $storedPath);
                } catch (Throwable) {
                    // Deliberately swallowed. The caller needs the original
                    // failure, not a cleanup failure that replaced it.
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array{party_id?: string|null, project_id?: string|null, matter_id?: string|null}  $relations
     */
    private function attach(User $actor, Document $document, array $relations): void
    {
        $junctions = [
            'party_id' => [PartyDocument::class, 'party_id'],
            'project_id' => [ProjectDocument::class, 'project_id'],
            'matter_id' => [MatterDocument::class, 'matter_id'],
        ];

        foreach ($junctions as $key => [$model, $column]) {
            $ownerId = $relations[$key] ?? null;

            if ($ownerId === null) {
                continue;
            }

            $row = new $model;
            $row->{$column} = $ownerId;
            $row->document_id = $document->getKey();

            // The constraint carrier, written from the Document. Never from the
            // request, and never from the owner record — one source means the two
            // composite keys cannot disagree.
            $row->office_id = $document->office_id;

            $row->attached_by = $actor->getKey();
            $row->attached_at = Date::now();
            $row->save();
        }
    }
}
