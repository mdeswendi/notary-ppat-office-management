<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `UNIQUE (organization_id, code)` on `offices` — implementing D-037, closing
 * O-023.
 *
 * **Composite, not global.** An office code is a short handle that only means
 * anything inside its Organization, so two Organizations may each run an office
 * coded `PUSAT` without collision. Making it globally unique would let one
 * deployment's naming choices constrain another's, which is not a rule any
 * canonical document asks for.
 *
 * A forward migration rather than an edit to `create_offices_table`: that one
 * has been applied everywhere, and rewriting applied history is how two
 * databases end up disagreeing about what "migrated" means (D-023).
 *
 * D-037 originally scheduled this to land with the Office management
 * submilestone so the database rule and the Form Request rule would arrive
 * together. **That condition can no longer be met inside M1** — M1 ships no
 * Office write endpoint at all, so there is no validation layer to disagree
 * with, and deferring further would carry an already-decided invariant past the
 * milestone that closes its own scope. When Office management is eventually
 * built, its Form Request must carry the matching rule:
 *
 *     Rule::unique('offices', 'code')->where('organization_id', $organizationId)
 *
 * so a user sees a field error rather than a 500 from this constraint.
 *
 * Data safety: verified against the development database before writing this —
 * `offices` held 0 rows and the duplicate query returned nothing, so no existing
 * record conflicts. The migration adds a constraint and touches no data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            // Named explicitly so `down()` can drop it by name on PostgreSQL
            // rather than relying on the driver reconstructing the convention.
            $table->unique(['organization_id', 'code'], 'offices_organization_id_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->dropUnique('offices_organization_id_code_unique');
        });
    }
};
