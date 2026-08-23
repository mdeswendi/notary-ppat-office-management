<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a Document is attached to (M5.1, D-116).
 *
 * **Three of seven, and the other four are blocked rather than deferred**
 * (D-115). `03_DATABASE_ERD.md` section 14 recommends seven junction tables;
 * `property_documents`, `notary_deed_documents`, `ppat_deed_documents` and
 * `matter_requirement_documents` reference `properties` (batch 8, M7),
 * `notary_deeds` (batch 9, M6), `ppat_deeds` (batch 10, M7) and
 * `matter_requirements` — **none of which exists** — and a foreign key cannot
 * point at a table that is not there.
 *
 * None of the four is stubbed: not created empty, not created without its foreign
 * key, and not replaced by a polymorphic `documentable_type` column. Section 14
 * argues against the last explicitly — *"Prefer explicit junction tables over
 * overly generic polymorphic relationships where strong referential integrity is
 * important"* — and a legal document is exactly where it is important.
 *
 * ## Same-Office is structural, not validated
 *
 * Each junction carries an `office_id` **constraint carrier**, never independent
 * data, with two composite foreign keys resolving through it:
 *
 * ```text
 * party_documents (party_id,    office_id) -> parties   (id, office_id)
 * party_documents (document_id, office_id) -> documents (id, office_id)
 * ```
 *
 * so a Document cannot be attached to a Party, Project or Matter belonging to
 * another Office — unrepresentable rather than merely refused, **including for an
 * actor holding `ALL`**, because `ALL` grants reach and administrative visibility
 * and never permission to redefine domain ownership. The construction
 * `company_people` (D-080), `project_parties` (D-098) and `matter_parties`
 * (D-105) all use.
 *
 * Every support key already exists: `parties` since M2.1, `projects` since M3.4,
 * `matters` since M4.2, and `documents` from this milestone's first migration. **No
 * support key is added here, so none is dropped on rollback.**
 *
 * ## RESTRICT everywhere, and no cardinality rule
 *
 * Every foreign key is `RESTRICT`: removing a Party must never take a document
 * with it, and a document must never become unreachable because something it was
 * attached to went away.
 *
 * **No `UNIQUE (party_id, document_id)`.** Following D-105 and D-110 exactly: no
 * canonical document says a Document may be attached to a Party only once, and a
 * unique index is a business rule wearing an index's clothing. If an office
 * decides duplicates are wrong, that is a rule to state and validate.
 *
 * ## A relationship, not a history
 *
 * `attached_at` and `attached_by` and nothing else — no `updated_at`, because a
 * junction row is never edited, and no `deleted_at`, because detaching removes
 * the row. The `project_parties` position (D-098), one domain across.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'party_documents' => ['party_id', 'parties', 'party'],
            'project_documents' => ['project_id', 'projects', 'project'],
            'matter_documents' => ['matter_id', 'matters', 'matter'],
        ] as $tableName => [$ownerColumn, $ownerTable, $shortName]) {
            Schema::create($tableName, function (Blueprint $table) use (
                $tableName, $ownerColumn, $ownerTable, $shortName
            ): void {
                $table->ulid('id')->primary();

                $table->ulid($ownerColumn);
                $table->ulid('document_id');

                // Constraint carrier. Never independent data and never request
                // input: written from the Document and then checked against both
                // endpoints by the composite keys below.
                $table->ulid('office_id');

                // Plain keys so a row cannot point at a nonexistent record...
                $table->foreign($ownerColumn, "{$tableName}_{$shortName}_foreign")
                    ->references('id')->on($ownerTable)->restrictOnDelete();

                $table->foreign('document_id', "{$tableName}_document_foreign")
                    ->references('id')->on('documents')->restrictOnDelete();

                // ...and the composite keys that make the Office agreement
                // structural. Both endpoints resolve through the same
                // `office_id`, so they cannot disagree with each other.
                $table->foreign([$ownerColumn, 'office_id'], "{$tableName}_{$shortName}_office_foreign")
                    ->references(['id', 'office_id'])->on($ownerTable)->restrictOnDelete();

                $table->foreign(['document_id', 'office_id'], "{$tableName}_document_office_foreign")
                    ->references(['id', 'office_id'])->on('documents')->restrictOnDelete();

                $table->foreignUlid('attached_by')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->timestamp('attached_at');

                // Ordinary lookup indexes, no uniqueness. The first answers
                // "what is attached to this record", the second "where else does
                // this document appear".
                $table->index($ownerColumn, "{$tableName}_{$shortName}_index");
                $table->index('document_id', "{$tableName}_document_index");
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_documents');
        Schema::dropIfExists('project_documents');
        Schema::dropIfExists('party_documents');
    }
};
