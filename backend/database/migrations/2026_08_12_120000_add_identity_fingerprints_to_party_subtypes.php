<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keyed blind fingerprints for the sensitive identifiers (M2.5, D-086).
 *
 * M2.0 deferred the sensitive-identifier duplicate mechanism and M2.1
 * deliberately added no fingerprint column, because the construction had not
 * been settled and a column added on speculation is one somebody fills in
 * wrongly. D-086 settles it, so the columns arrive now.
 *
 * `HMAC-SHA-256` in lowercase hex is always 64 characters, so `char(64)` is
 * exact rather than generous.
 *
 * **Nullable**, because the identifiers they derive from are nullable, and an
 * absent identifier must have an absent fingerprint — a shared "fingerprint of
 * nothing" would make every record without a NIK a duplicate of every other.
 *
 * **Indexed but deliberately NOT unique.** The index exists because equality
 * lookup is the entire point. Uniqueness is refused for the reasons D-084
 * already gives and D-086 restates: a unique index asserts that two rows sharing
 * a value are the same entity and that the value is always known and correctly
 * entered, none of which holds for optional, sometimes-mistyped, Office-scoped
 * identifiers. It would also turn a rejected insert into a cross-office
 * existence oracle — precisely what the Office-scoped duplicate rules exist to
 * prevent — and would convert advisory detection into blocking enforcement,
 * which is not what M2 decided.
 *
 * These columns are **internal metadata**. They are hidden at the model, absent
 * from every Resource, and not disclosed even to a holder of the full-view
 * reveal permission: that permission authorizes the identifier through the
 * reviewed reveal surface, not the cryptographic material derived from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('individuals', function (Blueprint $table): void {
            $table->char('nik_fingerprint', 64)->nullable()->after('nik');
            $table->char('npwp_fingerprint', 64)->nullable()->after('npwp');

            $table->index('nik_fingerprint', 'individuals_nik_fingerprint_index');
            $table->index('npwp_fingerprint', 'individuals_npwp_fingerprint_index');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->char('tax_id_fingerprint', 64)->nullable()->after('tax_id');

            $table->index('tax_id_fingerprint', 'companies_tax_id_fingerprint_index');
        });
    }

    public function down(): void
    {
        Schema::table('individuals', function (Blueprint $table): void {
            $table->dropIndex('individuals_nik_fingerprint_index');
            $table->dropIndex('individuals_npwp_fingerprint_index');
            $table->dropColumn(['nik_fingerprint', 'npwp_fingerprint']);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex('companies_tax_id_fingerprint_index');
            $table->dropColumn('tax_id_fingerprint');
        });
    }
};
