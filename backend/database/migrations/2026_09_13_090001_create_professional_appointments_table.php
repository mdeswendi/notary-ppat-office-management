<?php

use App\Domains\Party\Enums\PartyType;
use App\Domains\Professional\Enums\ProfessionalRelationshipType;
use App\Domains\Professional\Enums\ProfessionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A professional appointment belongs to an Individual Party. It is separate
 * from employment and system access: an external collaborating Notary may be
 * recorded without receiving a User account, while the same Party can retain
 * appointment history over time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_appointments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('office_id');
            $table->ulid('party_id');
            $table->string('party_type', 20)->default(PartyType::INDIVIDUAL->value);
            $table->string('profession_type', 20);
            $table->string('relationship_type', 20);
            $table->string('registration_number')->nullable();
            $table->date('appointed_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->string('professional_office_name')->nullable();
            $table->string('office_city')->nullable();
            $table->string('province')->nullable();
            $table->string('jurisdiction')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('office_id')->references('id')->on('offices')->restrictOnDelete();
            $table->foreign(['party_id', 'party_type'])
                ->references(['id', 'party_type'])->on('parties')->restrictOnDelete();
            $table->foreign(['party_id', 'office_id'])
                ->references(['id', 'office_id'])->on('parties')->restrictOnDelete();

            $table->unique(['id', 'office_id'], 'professional_appointments_id_office_unique');
            $table->index(['office_id', 'profession_type', 'is_active'], 'professional_appointments_office_profession_active_index');
            $table->index('party_id', 'professional_appointments_party_index');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $professions = implode("', '", ProfessionType::values());
            $relationships = implode("', '", ProfessionalRelationshipType::values());
            $connection = Schema::getConnection();
            $connection->statement("ALTER TABLE professional_appointments ADD CONSTRAINT professional_appointments_profession_check CHECK (profession_type IN ('{$professions}'))");
            $connection->statement("ALTER TABLE professional_appointments ADD CONSTRAINT professional_appointments_relationship_check CHECK (relationship_type IN ('{$relationships}'))");
            $connection->statement('ALTER TABLE professional_appointments ADD CONSTRAINT professional_appointments_dates_check CHECK (ended_at IS NULL OR appointed_at IS NULL OR ended_at >= appointed_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_appointments');
    }
};
