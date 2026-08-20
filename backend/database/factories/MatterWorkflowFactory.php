<?php

namespace Database\Factories;

use App\Models\MatterWorkflow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<MatterWorkflow>
 */
class MatterWorkflowFactory extends Factory
{
    /**
     * Supplies no endpoints deliberately. A workflow run needs a Matter and a
     * template that already share an Office, and a factory that invented both
     * would build combinations the real instantiation path refuses. A test
     * wanting one constructs it explicitly, which is the point.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_version' => 1,
            'started_at' => Date::now(),
            'completed_at' => null,
        ];
    }
}
