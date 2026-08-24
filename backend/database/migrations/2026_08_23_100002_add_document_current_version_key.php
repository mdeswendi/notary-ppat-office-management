<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The composite key that keeps `documents.current_version_id` honest
 * (M5.1, D-116).
 *
 * ```text
 * documents (id, current_version_id)  ->  document_versions (document_id, id)
 * ```
 *
 * **Its own migration because the two tables reference each other.** `documents`
 * cannot declare this inline — `document_versions` does not exist yet — and
 * `document_versions.document_id` points the other way, so one of the two keys
 * has to arrive by `ALTER` after both tables do.
 *
 * ## Why the pointer needs a *composite* key
 *
 * A plain `current_version_id -> document_versions.id` would let a Document name
 * a version belonging to some **other** Document, and nothing would object.
 * Including the document's own `id` on both sides makes that unrepresentable — the
 * construction `company_people` (D-080), `project_parties` (D-098), `matters`
 * (D-107), `matter_parties` (D-105) and `workflow_templates` (D-111) all use for
 * the same-Office invariant, applied here to a same-Document one.
 *
 * A composite key with a NULL component is satisfied, so a Document with no file
 * yet — `current_version_id` null — stays valid. That is the ordinary state
 * between creating the row and writing the first version.
 *
 * ## `RESTRICT`, after measuring that `NO ACTION` would behave identically
 *
 * `document_versions.document_id` cascades, so hard-deleting a Document deletes
 * versions that the very same Document row still points at. That looks as though
 * it should force `NO ACTION` — checked at end of statement — over `RESTRICT`,
 * which PostgreSQL checks immediately.
 *
 * **It does not, and this was measured rather than reasoned about.** Both
 * declarations accept the delete, because the referencing `documents` row is
 * removed by the very statement that triggers the cascade: by the time
 * `RESTRICT` looks for a row still pointing at the version, there is none. The
 * M5.1 PostgreSQL probe ran the same hard delete under each declaration in turn
 * and both succeeded.
 *
 * They are also identical in the opposite direction: deleting a version while
 * its Document survives is refused either way.
 *
 * So the choice is uniformity, not behaviour. `RESTRICT` is what every other
 * foreign key in this schema declares — `document_versions.document_id` is the
 * single, deliberate CASCADE — and a lone `NO ACTION` would invite the next
 * reader to hunt for a difference that is not there. The two diverge only for
 * `DEFERRABLE INITIALLY DEFERRED` constraints, which this schema does not use.
 *
 * `DocumentSchemaTest` hard-deletes a Document that has versions and asserts the
 * cascade completes, so the behaviour is pinned on both engines rather than
 * resting on this note.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite cannot add a foreign key to an existing table, and Laravel's
        // schema builder would rebuild the table to fake it — losing the
        // constraint's semantics in the process. The test suite runs on SQLite,
        // where foreign keys are enforced but this particular ALTER is not
        // available, so the composite key is a PostgreSQL guarantee and the
        // model guard below it is what holds on both.
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement(
            'ALTER TABLE documents ADD CONSTRAINT documents_current_version_foreign '
            .'FOREIGN KEY (id, current_version_id) '
            .'REFERENCES document_versions (document_id, id) '
            .'ON DELETE RESTRICT'
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign('documents_current_version_foreign');
        });
    }
};
