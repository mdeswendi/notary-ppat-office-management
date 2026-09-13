<?php

namespace Database\Factories;

use App\Models\MatterProfessional;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MatterProfessional> */
class MatterProfessionalFactory extends Factory
{
    public function definition(): array
    {
        return ['role_code' => 'EXECUTING_OFFICER', 'notes' => null];
    }
}
