<?php

namespace Database\Factories;

use App\Models\MatterStageHistory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<MatterStageHistory>
 */
class MatterStageHistoryFactory extends Factory
{
    /**
     * `reason` is left null rather than given sample prose: it is a free-text
     * leak surface (D-105), and a fixture that filled it would be the first
     * example somebody copies.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_stage_code' => null,
            'to_stage_code' => 'UJI_TAHAP_1',
            'reason' => null,
            'changed_at' => Date::now(),
        ];
    }
}
