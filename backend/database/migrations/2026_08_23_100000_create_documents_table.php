<?php

use App\Domains\Document\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Document record: what a document is, whose it is, and what state it is in
 * (M5.1, D-116).
 *
 * **A Document is not a file.** The file lives on a version
 * (`document_versions`), and this table carries identity, classification and
 * state. That separation is what makes "never overwrite a version" expressible
 * at all (`CLAUDE.md` section 19): correcting a document adds a version, and the
 * previous bytes stay exactly where they were.
 *
 * **Backend foundation only** — no route, controller, request, resource or
 * frontend, following M2.1, M3.1, M4.1, M4.2 and M4.6. **No permission is
 * registered; the count stays at 177**: all nine `documents.*` codes have been
 * canonical since the catalogue was transcribed.
 *
 * ## The current-version pointer, and why it is a composite key
 *
 * M5.0 handed M5.1 an explicit choice between a partial unique index on a
 * boolean, an application invariant, and this pointer. **The pointer wins**: a
 * partial index does not exist on the SQLite connection the test suite runs on,
 * so PostgreSQL and SQLite would disagree about what is representable — the shape
 * D-111 already refused once — while a single column makes "at most one current
 * version" structural rather than something a rule has to maintain.
 *
 * **But a bare pointer would not prove the version belongs to this document.**
 * `current_version_id` could name a version of some *other* document, and nothing
 * would object. So it is a composite foreign key through the document's own id:
 *
 * ```text
 * documents (id, current_version_id)  ->  document_versions (document_id, id)
 * ```
 *
 * the same construction `company_people` (D-080), `project_parties` (D-098),
 * `matters` (D-107), `matter_parties` (D-105) and `workflow_templates` (D-111)
 * all use. A cross-document pointer becomes unrepresentable rather than merely
 * wrong.
 *
 * **The key is added by a later migration, not here.** The two tables reference
 * each other, so neither can declare its foreign key inline; `documents` is
 * created with the column and without the constraint, and
 * `..._100002_add_document_current_version_key` adds it once both exist.
 *
 * ## What is nullable now and tightened later
 *
 * `document_number` is nullable, exactly as `project_number` was until M3.3 and
 * `matter_number` until M4.4: **no creation path allocates one yet**, so `NOT
 * NULL` would make a Document unwritable for a whole milestone. The milestone
 * that builds upload stamps it inside the creating transaction and tightens the
 * column (D-095's rule, twice proven).
 *
 * `current_version_id` is nullable **permanently**, not pending: a Document may
 * legitimately exist before its first file lands, and the upload path creates the
 * row, writes the version, then points at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Office ownership is the security boundary and the OFFICE scope
            // predicate. `restrictOnDelete` matches every other owned record:
            // removing an Office must never silently take its documents with it.
            // Indexed explicitly — PostgreSQL does not index a referencing column
            // just because it carries a foreign key.
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // Internal office identification and nothing more. **Never a legal
            // number** — not a deed number, not a repertorium entry, not a land
            // registration number (D-103's distinction, one domain across).
            // `DOC-YYYY-NNNNNN`, allocated per Office and calendar year.
            $table->string('document_number', 32)->nullable();

            // Opaque classification, nullable, and deliberately **not** an enum
            // or a CHECK. No canonical document defines a document-type
            // catalogue: `KTP`, `NPWP` and `AKTA` appear in prose as examples,
            // and constraining the column would turn examples into the catalogue
            // the documents never claimed (D-105's `role_code` reasoning,
            // D-115).
            $table->string('document_type_code', 50)->nullable();

            $table->string('title');

            // Stable machine codes, never translated labels (CLAUDE.md section
            // 12). CHECK-constrained below rather than a PostgreSQL native ENUM,
            // per section 13.
            $table->string('status', 30);
            $table->index('status', 'documents_status_index');

            // **Set by whoever uploads, never inferred from the type** (D-115).
            // Deriving it from `document_type_code` would encode which document
            // kinds are sensitive — a judgement that varies by office and one no
            // canonical document makes.
            $table->boolean('is_sensitive')->default(false);
            $table->index('is_sensitive', 'documents_is_sensitive_index');

            // The date on the document itself — the date a KTP was issued, not
            // the date it was filed here.
            $table->date('document_date')->nullable();

            // Canonical, and **nothing acts on it in M5**. Whether an expired
            // document is refused, flagged, or merely displayed is a business
            // rule no document defines, and expiring a legal record is not an
            // engineering decision.
            $table->date('expiry_date')->nullable();

            $table->text('notes')->nullable();

            // The current-version pointer. See the class docblock: the composite
            // foreign key that keeps it honest is added once
            // `document_versions` exists.
            $table->ulid('current_version_id')->nullable();

            // Attribution survives the person who typed it (D-050).
            $table->foreignUlid('created_by')
                ->index()
                ->constrained('users')
                ->restrictOnDelete();

            // **Present because `03_DATABASE_ERD.md` section 13 lists it**, and
            // for the reason `matters` carries the same pair: a metadata
            // correction has an author, and `updated_at` alone records that
            // something changed without recording who.
            $table->foreignUlid('updated_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            // Archiving is a state, not a deletion. `CLAUDE.md` section 30 and
            // ERD section 33 both prefer archive over hard delete for legal
            // records, and `ARCHIVED` is a business status rather than a
            // persistence one — the D-102 distinction.
            $table->timestamp('archived_at')->nullable();

            $table->foreignUlid('archived_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            // **Reserved capability with no lifecycle in M5.1.** The column
            // exists because the ERD carries it and because `documents.delete`
            // is canonical and "must be heavily restricted" (section 13 of
            // `02_MENU_AND_PERMISSIONS.md`); no code path reaches it yet, and the
            // model deliberately uses no `SoftDeletes` — the M4.2 position, so
            // "invisible because deleted" cannot be confused with "invisible
            // because out of scope" before the milestone that owns deletion
            // exists to decide it.
            $table->softDeletes();

            // A document number identifies one document **within its Office**.
            // Composite, never global: two Offices may both hold
            // `DOC-2026-000001`, and a global index would fail the second
            // office's first document for no explicable reason.
            $table->unique(['office_id', 'document_number'], 'documents_office_id_document_number_unique');

            // The support key the junction tables will need at M5.3, added now
            // rather than by a later ALTER — the M4.1 habit. `party_documents`,
            // `project_documents` and `matter_documents` each need the
            // same-Office guarantee to be structural rather than validated.
            $table->unique(['id', 'office_id'], 'documents_id_office_id_unique');
        });

        // Only canonical codes are storable. A CHECK rather than a PostgreSQL
        // native ENUM, per CLAUDE.md section 13 — the enum lives in PHP, and the
        // database refuses anything the enum does not name.
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $statuses = implode("', '", DocumentStatus::values());

            $connection->statement(
                "ALTER TABLE documents ADD CONSTRAINT documents_status_check CHECK (status IN ('{$statuses}'))"
            );
        }

        // SQLite cannot add a CHECK after the fact, and the test suite runs
        // there. The enum cast on the model is what refuses an invalid value on
        // that connection.
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
