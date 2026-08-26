<?php

namespace Database\Factories;

use App\Models\Disbursement;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Disbursement>
 */
class DisbursementFactory extends Factory
{
    protected $model = Disbursement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'description' => $this->faker->sentence(3),
            'amount' => '250000.00',
            'currency' => 'IDR',
            'incurred_on' => now()->toDateString(),
        ];
    }

    public function inOffice(Office $office, ?User $creator = null): static
    {
        $creator ??= User::factory()->for($office)->create();

        return $this->state(fn (): array => [
            'office_id' => $office->getKey(),
            'created_by' => $creator->getKey(),
            'updated_by' => $creator->getKey(),
        ]);
    }
}
