<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('office_id')->index()->constrained('offices')->restrictOnDelete();
            $table->foreignUlid('project_id')->nullable()->index()->constrained('projects')->nullOnDelete();
            $table->foreignUlid('matter_id')->nullable()->index()->constrained('matters')->nullOnDelete();
            $table->string('event_type', 30);
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->nullable();
            $table->string('location')->nullable();
            $table->foreignUlid('created_by')->index()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
