<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One uploaded file, written once (M5.1, D-116).
 *
 * `CLAUDE.md` sections 19 and 20 and `03_DATABASE_ERD.md` section 13 all say the
 * same thing three ways: **never overwrite an existing version.** This table is
 * where that becomes structural — a correction adds a row, and the previous
 * file's bytes, path and checksum stay exactly as they were.
 *
 * ## A version has no `updated_at`, deliberately
 *
 * `03_DATABASE_ERD.md` section 13 gives this table `uploaded_at` and **no
 * `created_at` or `updated_at`**, and that is transcribed rather than tidied. An
 * `updated_at` would advertise a mutation that must never happen: there is no
 * legitimate edit to a version, so a column recording when one occurred is a
 * column inviting one. `uploaded_at` *is* the creation time, and adding
 * `created_at` beside it would be two names for one fact.
 *
 * The model turns Eloquent's automatic timestamps off to match, following
 * `project_parties` (D-098).
 *
 * ## `is_current` is absent, and that is the M5.0 ruling resolved
 *
 * The ERD lists `is_current` here. M5.1 replaces it with
 * `documents.current_version_id` (D-116): a boolean would need a partial unique
 * index to mean "exactly one", partial indexes do not exist on the SQLite
 * connection the tests run on, and D-111 already refused that shape once. The
 * pointer makes the same rule structural on both engines.
 *
 * The **support key below is what keeps the pointer honest.** `UNIQUE (document_id,
 * id)` is redundant for uniqueness — `id` is already the primary key — but a
 * composite foreign key needs a unique index on the exact referenced pair, and
 * without it `documents` could point at a version belonging to another document.
 *
 * ## Storage
 *
 * `storage_path` is a path on a **private disk** and must never contain `public/`
 * or `uploads/` (`CLAUDE.md` section 19). M5.0 additionally turned off the
 * `serve` flag that exposed that directory over HTTP (D-114), so a file is
 * reachable only by streaming it from a surface that authorized the actor first.
 *
 * `stored_filename` is generated and unguessable; `original_filename` is kept so
 * a download can restore the name the uploader knew, and is **never** used as a
 * path component — user-supplied names carry traversal sequences, case collisions
 * and characters no filesystem agrees about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // CASCADE: a version has no existence apart from its document. It is
            // not a record the office keeps separately — it is one revision of
            // one document.
            //
            // The consequence is stated rather than discovered later: because
            // `documents.current_version_id` points back here, hard-deleting a
            // document deletes versions the document itself still references.
            // That delete succeeds — the referencing `documents` row goes in the
            // same statement, so nothing is left pointing at the version. The
            // next migration measured it under both `RESTRICT` and `NO ACTION`
            // and records the result.
            $table->foreignUlid('document_id')
                ->index()
                ->constrained('documents')
                ->cascadeOnDelete();

            // Allocated per document, atomically, never `MAX+1` (D-103's rule).
            // Signed on PostgreSQL whatever Laravel's type name suggests, so the
            // CHECK below is what keeps it positive.
            $table->unsignedInteger('version_number');

            // Which disk the bytes are on. Recorded rather than assumed, so a
            // deployment that later moves to S3 can still find files written
            // before the move.
            $table->string('storage_disk', 30);

            // Never `public/`, never `uploads/`. A test asserts it.
            $table->string('storage_path');

            $table->string('original_filename');
            $table->string('stored_filename');

            $table->string('mime_type', 150);

            // Bytes. Signed on PostgreSQL, so the CHECK below enforces
            // non-negativity — the M4.1 `default_duration_days` lesson.
            $table->unsignedBigInteger('file_size');

            // SHA-256, lowercase hex, exactly 64 characters. It exists so a later
            // reader can prove the file is the one that was uploaded, which is
            // only meaningful because nothing ever rewrites it.
            $table->char('checksum_sha256', 64);

            $table->foreignUlid('uploaded_by')
                ->index()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('uploaded_at');

            // **No `timestamps()`.** See the class docblock: the ERD gives this
            // table `uploaded_at` alone, and an `updated_at` would record a
            // mutation that must never occur.

            // One row per position within a document.
            $table->unique(['document_id', 'version_number'], 'document_versions_document_version_unique');

            // The support key `documents (id, current_version_id)` references.
            // Redundant for uniqueness, required for the composite foreign key.
            $table->unique(['document_id', 'id'], 'document_versions_document_id_id_unique');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            // Laravel's `unsigned*` types are MySQL concepts; PostgreSQL maps
            // them to signed columns without complaint. The M4.1 lesson, applied
            // to two more columns before it can bite.
            $connection->statement(
                'ALTER TABLE document_versions ADD CONSTRAINT document_versions_version_number_check '
                .'CHECK (version_number >= 1)'
            );

            $connection->statement(
                'ALTER TABLE document_versions ADD CONSTRAINT document_versions_file_size_check '
                .'CHECK (file_size >= 0)'
            );

            // A private path, enforced by the database rather than only by the
            // service that writes it. `CLAUDE.md` section 19 names both
            // directories explicitly, and a constraint is what makes a future
            // caller unable to route around the rule.
            $connection->statement(
                'ALTER TABLE document_versions ADD CONSTRAINT document_versions_storage_path_private_check '
                ."CHECK (storage_path NOT LIKE 'public/%' AND storage_path NOT LIKE '%/public/%' "
                ."AND storage_path NOT LIKE 'uploads/%' AND storage_path NOT LIKE '%/uploads/%')"
            );

            // Lowercase hex, exactly 64 characters. A truncated or uppercased
            // digest would compare unequal to a correctly computed one and the
            // mismatch would look like corruption.
            $connection->statement(
                'ALTER TABLE document_versions ADD CONSTRAINT document_versions_checksum_format_check '
                ."CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
