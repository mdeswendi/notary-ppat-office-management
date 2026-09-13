<?php

namespace App\Domains\Professional\Actions;

use App\Domains\Party\Enums\PartyType;
use App\Domains\Professional\Enums\ProfessionalRelationshipType;
use App\Domains\Professional\Enums\ProfessionType;
use App\Models\Office;
use App\Models\Party;
use App\Models\ProfessionalAppointment;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AddProfessionalAppointment
{
    public function handle(
        Office $office,
        Party $party,
        ProfessionType $profession,
        ProfessionalRelationshipType $relationship,
        array $attributes,
    ): ProfessionalAppointment {
        return DB::transaction(function () use ($office, $party, $profession, $relationship, $attributes): ProfessionalAppointment {
            Office::query()->whereKey($office->getKey())->lockForUpdate()->firstOrFail();

            $duplicate = ProfessionalAppointment::query()
                ->where('office_id', $office->getKey())
                ->where('party_id', $party->getKey())
                ->where('profession_type', $profession->value)
                ->where('relationship_type', $relationship->value)
                ->where('is_active', true)
                ->exists();

            if ($duplicate) {
                throw new ConflictHttpException('This professional appointment is already active.');
            }

            $appointment = new ProfessionalAppointment;
            $appointment->office_id = $office->getKey();
            $appointment->party_id = $party->getKey();
            $appointment->party_type = PartyType::INDIVIDUAL;
            $appointment->profession_type = $profession;
            $appointment->relationship_type = $relationship;
            $appointment->fill($attributes);
            $appointment->save();

            return $appointment->fresh(['party' => fn ($query) => $query->withTrashed()]);
        });
    }
}
