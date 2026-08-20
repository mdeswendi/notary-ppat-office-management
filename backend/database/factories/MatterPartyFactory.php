<?php

namespace Database\Factories;

use App\Models\MatterParty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterParty>
 */
class MatterPartyFactory extends Factory
{
    /**
     * Deliberately supplies no endpoints. A participation needs a Matter and a
     * Party that already share an Office, and a factory that invented both would
     * quietly build the very cross-office case the composite foreign keys exist
     * to forbid — a test wanting one must construct it explicitly, which is the
     * point.
     *
     * `role_code` is left null rather than given a plausible-looking default: no
     * canonical vocabulary exists, and seeding `SELLER` everywhere would make an
     * invented catalogue look established (D-105).
     *
     * Timestamps are left to Eloquent, unlike {@see ProjectPartyFactory}: this
     * table has an `updated_at`, so automatic timestamps stay on.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_code' => null,
            'notes' => null,
        ];
    }
}
