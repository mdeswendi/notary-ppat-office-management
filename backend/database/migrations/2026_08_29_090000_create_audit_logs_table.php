<?php

use App\Domains\Audit\Enums\AuditEvent;
use App\Domains\Audit\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed what, and from where (M8.1, D-123).
 *
 * Transcribed from `03_DATABASE_ERD.md` section 25, field for field, including
 * the section's own closing note:
 *
 * > No: `updated_at`, `deleted_at`. Audit logs are append-only.
 *
 * **This table has been owed since M1.** D-033 kept it out on the ERD's batch
 * ordering — section 32 places `audit` in batch **7** — and D-115 kept it out of
 * M5 on the same ground while ruling that no sensitive-download surface ships
 * before it exists. M5 built `tasks` from batch 7 and left this; M6 built batch 8
 * and M7 batches 8 and 10, both *later*. The ordering argument that deferred it
 * three times is now the argument for building it.
 *
 * ## Append-only is structural here, not a convention
 *
 * There is no `updated_at` and no `deleted_at`, so `timestamps()` and
 * `softDeletes()` are both deliberately absent. The model refuses updates and
 * deletes outright ({@see AuditLog}), and `CLAUDE.md`
 * section 31's prohibition on `audit.update` / `audit.delete` extends to there
 * being no internal method that could perform one.
 *
 * ## `event` carries no CHECK constraint, and that is deliberate
 *
 * The ERD names the column and defines **no vocabulary for it** — unlike
 * `matters.status` or `tasks.status`, where a canonical list exists and the
 * CHECK transcribes it. Freezing a vocabulary the ERD does not define would make
 * every future milestone's new event a migration, and would repeat the mistake
 * M7.1 avoided when `ppat_warkah_items.status` was left unconstrained because the
 * ERD gave it no values.
 *
 * {@see AuditEvent} is what the application writes, and
 * it is the application's list rather than a transcribed one.
 *
 * ## The actor key is plain, and this is the one table where that is correct
 *
 * Every office-owned table since M2 pairs a user column with `office_id` in a
 * composite foreign key, so the person named must work in the record's own
 * Office — `company_people` (D-080), `project_parties` (D-098), `matters`
 * (D-107), `tasks` (D-119). **This table deliberately breaks that pattern**, and
 * the reason is the whole point of an audit trail.
 *
 * `office_id` is the **subject's** Office, because that is who is entitled to
 * read the row. The actor may legitimately be somebody else: an actor holding
 * Data Scope `ALL` reaches records across Offices by design, and revealing
 * another Office's NIK is precisely the event an auditor most needs recorded.
 * A composite key would make that row **unrepresentable** — so the most
 * security-relevant access in the system would be the one act that could not be
 * written down.
 *
 * The composite version was written first and a test caught it: an `ALL`-scope
 * reveal against another Office failed the foreign key. Filing the row under the
 * actor's Office instead would have "fixed" it by hiding the record from the
 * only people entitled to see it.
 *
 * So: a plain key to `users.id`, and `office_id` keyed to `offices`
 * independently. `actor_user_id` is **nullable** for a system-initiated event.
 *
 * **`restrictOnDelete`, never `nullOnDelete`.** An audit record whose actor was
 * erased is not an audit record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The security boundary and the OFFICE scope predicate. Indexed
            // explicitly — PostgreSQL does not index a referencing column just
            // because it carries a foreign key.
            $table->foreignUlid('office_id')
                ->index()
                ->constrained('offices')
                ->restrictOnDelete();

            // Nullable: a system-initiated event has no actor. **Plain, not
            // composite** — see the class docblock. The actor is not required to
            // belong to the subject's Office, because cross-office access is
            // exactly what this table exists to record.
            $table->ulid('actor_user_id')->nullable();

            $table->string('event', 60);

            // Polymorphic by design — the ERD names `auditable_type` and
            // `auditable_id` rather than a column per domain. `auditable_id` is a
            // string because every domain key in this system is a ULID, and
            // keeping it textual means a future non-ULID subject costs nothing.
            $table->string('auditable_type');
            $table->string('auditable_id', 40);

            // What changed. Never the values themselves for a masked field —
            // D-105's leak-surface rule, restated with more force by D-115: an
            // audit row records *that* a sensitive field changed, not what it
            // changed from and to.
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();

            // 45 characters holds an IPv6 address with an IPv4 tail.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('reason')->nullable();

            // Not `timestamps()`. There is no `updated_at` and never will be.
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('actor_user_id', 'audit_logs_actor_foreign')
                ->references('id')->on('users')->restrictOnDelete();

            // The three questions an audit trail is actually asked: what happened
            // in this Office lately, what happened to this record, and what has
            // this person done.
            $table->index(['office_id', 'event', 'created_at'], 'audit_logs_office_event_created_index');
            $table->index(['office_id', 'created_at'], 'audit_logs_office_created_index');
            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_auditable_index');
            $table->index('actor_user_id', 'audit_logs_actor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
