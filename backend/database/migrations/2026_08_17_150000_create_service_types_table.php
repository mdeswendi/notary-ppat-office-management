<?php

use App\Domains\MasterData\Enums\ServiceTypeDomain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service Type — the office's own catalogue of the work it offers (M4.1, D-102).
 *
 * Fields follow `03_DATABASE_ERD.md` section 8. ULID primary key per D-023,
 * Office ownership per the ERD, which gives this table an `office_id` and gives
 * the deployment-global tables none.
 *
 * **The table ships empty and stays empty.** No canonical Notary or PPAT service
 * catalogue exists — it is the first open question in both workflow drafts — and
 * D-102 forbids inferring one. Nothing here seeds a row, and the factory's values
 * are deliberately non-domain so no fixture can be mistaken for an approved
 * service.
 *
 * **Retirement is `is_active`, not deletion.** There is no `deleted_at`, no
 * archive, and no restore, and the canonical registry offers no code that could
 * authorize one — `master.services.view` and `master.services.manage` are the
 * whole surface. The `offices` migration set this precedent in the same words:
 * retirement uses `is_active`. It is also the only choice that survives M4.2: a
 * Matter that referenced a deleted Service Type would lose the classification a
 * historical record depends on, which CLAUDE.md section 63 forbids.
 *
 * Deliberately absent, each for a stated reason:
 *
 *   deleted_at              retirement is `is_active`; nothing deletes a
 *                           Service Type, so a soft delete would be a half
 *                           mechanism with no surface to honour it
 *   created_by, updated_by  the ERD lists neither, and neither does `offices`.
 *                           Reference data is not owned by whoever typed it;
 *                           `OWN` is withheld from its Data Scopes for the same
 *                           reason (D-080's reasoning, one domain across)
 *   legal_term,             listed in the ERD and defined nowhere in the
 *   preserve_legal_term     repository — three readings are all plausible, and a
 *                           separate `legal_terms` table exists with its own
 *                           permissions. Withheld until the semantics are
 *                           validated, exactly as M3.1 withheld `project_number`
 *                           until its construction was settled (D-095, D-086)
 *   workflow_template_id    a Service Type -> Workflow relationship belongs to
 *                           M4.6, which owns workflow templates
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Office ownership is the security boundary and the OFFICE scope
            // predicate. Required and immutable — the guard for immutability
            // lives in the model, because it is an update rule and a column
            // cannot express one.
            //
            // `restrictOnDelete` matches `projects.office_id` and
            // `parties.office_id`: removing an Office must never silently take
            // its catalogue with it. Indexed explicitly — PostgreSQL does not
            // index a referencing column just because it carries a foreign key.
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // A stable classification handle, entered by the office. **Not an
            // internal reference and not legal numbering**: it carries no
            // sequence, no year, and no legal weight, and must never be confused
            // with `matter_number` (D-103). No automatic numbering exists.
            //
            // Stored exactly as submitted. No case normalization: no canonical
            // document defines one, and inventing one would silently make
            // `AJB` and `ajb` the same code — or fail to — with no stated rule
            // either way. The O-023 lesson is the shape here: `offices.code`
            // shipped without a rule nobody had written, and the gap was flagged
            // rather than filled from memory.
            $table->string('code');

            // Stable machine codes, never translated labels (CLAUDE.md section
            // 12). Required and immutable: a Service Type belongs to exactly one
            // domain, and flipping it after Matters reference it would silently
            // reclassify work.
            $table->string('domain', 20);

            // The first bilingual columns in this schema, sanctioned by
            // CLAUDE.md section 10 and `05_I18N_LEGAL_TERMINOLOGY.md` section 3
            // for dynamic master content. Both are required: a catalogue entry
            // that cannot be displayed in one of the two supported locales is
            // incomplete, and falling back silently would hide that.
            $table->string('name_id');
            $table->string('name_en');

            $table->text('description_id')->nullable();
            $table->text('description_en')->nullable();

            // The only retirement mechanism in M4. An inactive Service Type is
            // unavailable for new selection and remains readable on every record
            // that already references it.
            $table->boolean('is_active')->default(true);

            // Presentation ordering for the catalogue, and nothing else. It
            // carries no legal or process meaning — it is not a stage sequence,
            // not a priority, and not a signing order.
            $table->integer('sort_order')->default(0);

            // Informational planning metadata only. **Not an SLA, not a workflow
            // deadline, and not a legal timing rule** — no canonical document
            // defines any of those, and `workflow_stages.target_days` is a
            // separate M4.6 concern.
            //
            // `unsignedInteger` does **not** make this non-negative on
            // PostgreSQL, which has no unsigned integer type and silently maps it
            // to `integer` — verified by inserting -1 during the M4.1 disposable
            // database run, which the database accepted. Non-negativity is
            // therefore enforced by the CHECK below, beside the domain one.
            $table->unsignedInteger('default_duration_days')->nullable();

            $table->timestamps();

            // A code identifies one service **within its Office**. Composite,
            // never global: two Offices may both run an `AJB`, and a global index
            // would fail the second office's first entry for no explicable
            // reason. The M1.10 resolution of O-023 reached the same shape for
            // `offices.code`, and the matching Form Request rule is carried
            // forward to whichever milestone builds the write surface.
            //
            // **`domain` is deliberately not part of this namespace.** One code
            // must not mean two different things inside one Office.
            $table->unique(['office_id', 'code'], 'service_types_office_id_code_unique');

            // The support key a composite foreign key needs, added now rather
            // than by a later ALTER (M4.1, anticipating M4.2). M4.2's
            // `matters.service_type_id` is intended to carry the same-Office
            // guarantee structurally, through
            // `(service_type_id, office_id) -> service_types (id, office_id)`,
            // the pattern `company_people` (D-080) and `project_parties` (D-098)
            // both use. `projects` gained its equivalent at M3.4 for exactly this
            // reason.
            //
            // No Matter table and no Matter foreign key is created here.
            $table->unique(['id', 'office_id'], 'service_types_id_office_id_unique');

            // The catalogue is read one Office at a time, filtered by domain, and
            // usually narrowed to what is still offered.
            $table->index(['office_id', 'domain', 'is_active'], 'service_types_office_domain_active_index');
        });

        // Only canonical codes are storable. A CHECK rather than a PostgreSQL
        // native ENUM, per CLAUDE.md section 13 — the enum lives in PHP, and the
        // database refuses anything the enum does not name.
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $domains = implode("', '", ServiceTypeDomain::values());

            $connection->statement(
                "ALTER TABLE service_types ADD CONSTRAINT service_types_domain_check CHECK (domain IN ('{$domains}'))"
            );

            // Nullable, so the constraint must permit NULL explicitly — the same
            // shape `projects_priority_check` uses. A duration counts days; a
            // negative one is not a shorter target, it is meaningless data.
            $connection->statement(
                'ALTER TABLE service_types ADD CONSTRAINT service_types_default_duration_days_check '
                .'CHECK (default_duration_days IS NULL OR default_duration_days >= 0)'
            );
        }

        // SQLite cannot add a CHECK after the fact, and the test suite runs
        // there. The enum cast on the model is what refuses an invalid value —
        // stated plainly rather than left to be discovered, exactly as the
        // `projects` and `parties` migrations say of their own coded columns.
    }

    public function down(): void
    {
        Schema::dropIfExists('service_types');
    }
};
