<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the professional capacity under which work is handled. This is not
 * a substitute for MatterParty: clients and signers participate in the act;
 * the professional appointment identifies the PPAT or Notary responsible for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_professionals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('office_id');
            $table->ulid('matter_id');
            $table->ulid('professional_appointment_id');
            $table->string('role_code', 30);
            $table->text('notes')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->foreign(['matter_id', 'office_id'])
                ->references(['id', 'office_id'])->on('matters')->restrictOnDelete();
            $table->foreign(['professional_appointment_id', 'office_id'], 'matter_professionals_appointment_office_foreign')
                ->references(['id', 'office_id'])->on('professional_appointments')->restrictOnDelete();
            $table->foreign(['created_by', 'office_id'], 'matter_professionals_created_by_office_foreign')
                ->references(['id', 'office_id'])->on('users')->restrictOnDelete();
            $table->unique(
                ['matter_id', 'professional_appointment_id', 'role_code'],
                'matter_professionals_matter_appointment_role_unique'
            );
            $table->index('office_id', 'matter_professionals_office_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_professionals');
    }
};
