<?php

use App\Domains\Ppat\PropertyReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes Property references system allocated per Office.
 *
 * Existing references already shaped as `PROP-NNNNNN` are preserved. Legacy,
 * blank, or mistyped values are replaced deterministically in creation order.
 * On the current production data this changes `prp` to `PROP-000001`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_reference_counters', function (Blueprint $table): void {
            $table->foreignUlid('office_id')->primary()->constrained('offices')->cascadeOnDelete();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });

        $properties = DB::table('properties')
            ->select(['id', 'office_id', 'property_number', 'created_at'])
            ->orderBy('office_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('office_id');

        foreach ($properties as $officeId => $officeProperties) {
            $used = [];
            $lastValue = 0;

            foreach ($officeProperties as $property) {
                $number = (string) ($property->property_number ?? '');

                if (! PropertyReference::matchesFormat($number)) {
                    continue;
                }

                $sequence = (int) substr($number, strlen(PropertyReference::PREFIX) + 1);
                $used[$sequence] = true;
                $lastValue = max($lastValue, $sequence);
            }

            foreach ($officeProperties as $property) {
                if (PropertyReference::matchesFormat((string) ($property->property_number ?? ''))) {
                    continue;
                }

                do {
                    $lastValue++;
                } while (isset($used[$lastValue]));

                DB::table('properties')
                    ->where('id', $property->id)
                    ->update(['property_number' => PropertyReference::format($lastValue)]);

                $used[$lastValue] = true;
            }

            DB::table('property_reference_counters')->insert([
                'office_id' => $officeId,
                'last_value' => $lastValue,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Normalized references remain on their Properties: restoring a mistyped
        // internal reference would reintroduce the data error this migration fixes.
        Schema::dropIfExists('property_reference_counters');
    }
};
