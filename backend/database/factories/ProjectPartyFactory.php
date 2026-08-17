<?php

namespace Database\Factories;

use App\Models\ProjectParty;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<ProjectParty>
 */
class ProjectPartyFactory extends Factory
{
    /**
     * Deliberately supplies no endpoints. A participation needs a Project and a
     * Party that already share an Office, and a factory that invented both would
     * quietly build the very cross-office case the composite foreign keys exist
     * to forbid — a test wanting one must construct it explicitly, which is the
     * point.
     *
     * `role_code` is left null rather than given a plausible-looking default:
     * no canonical vocabulary exists, and seeding `CLIENT` everywhere would make
     * an invented catalogue look established (D-092).
     *
     * `created_at` is stamped here because the model keeps Eloquent's automatic
     * timestamps off — the table has no `updated_at` counterpart.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_code' => null,
            'is_primary' => false,
            'notes' => null,
            'created_at' => Date::now(),
        ];
    }
}
