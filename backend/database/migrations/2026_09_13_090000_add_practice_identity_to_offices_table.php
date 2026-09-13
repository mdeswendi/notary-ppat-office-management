<?php

use App\Domains\Office\Enums\OfficePracticeType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->string('practice_type', 30)->nullable()->after('name');
            $table->string('jurisdiction')->nullable()->after('province');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $values = implode("', '", OfficePracticeType::values());
            Schema::getConnection()->statement(
                "ALTER TABLE offices ADD CONSTRAINT offices_practice_type_check CHECK (practice_type IN ('{$values}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->dropColumn(['practice_type', 'jurisdiction']);
        });
    }
};
