<?php

namespace Database\Factories;

use App\Domains\Party\Enums\PartyType;
use App\Domains\Professional\Enums\ProfessionalRelationshipType;
use App\Domains\Professional\Enums\ProfessionType;
use App\Models\ProfessionalAppointment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProfessionalAppointment> */
class ProfessionalAppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'party_type' => PartyType::INDIVIDUAL->value,
            'profession_type' => ProfessionType::PPAT->value,
            'relationship_type' => ProfessionalRelationshipType::INTERNAL->value,
            'appointed_at' => null,
            'ended_at' => null,
            'is_active' => true,
        ];
    }
}
