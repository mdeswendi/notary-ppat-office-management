<?php

namespace Database\Factories;

use App\Domains\Party\Enums\CompanyRelationshipType;
use App\Models\CompanyPerson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyPerson>
 */
class CompanyPersonFactory extends Factory
{
    /**
     * Deliberately supplies no default endpoints. A relationship needs a Company
     * and an Individual that already share an Office, and a factory that
     * invented both would quietly build the very cross-office case the schema
     * exists to forbid — a test wanting one must construct it explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'relationship_type' => CompanyRelationshipType::DIRECTOR,
            'position_name' => fake()->jobTitle(),
        ];
    }
}
