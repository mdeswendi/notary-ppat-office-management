<?php

namespace App\Domains\Ppat\Enums;

/**
 * What kind of land object this is (M7.1, D-121).
 *
 * Transcribed verbatim from `03_DATABASE_ERD.md` section 16, which gives these four
 * as a **flat closed list** — no hedging word, unlike `right_type` and `role_code`.
 * That is why this one is CHECK-constrained in the database and those are not.
 *
 * **`APARTMENT_UNIT`, not `APARTMENT`.** The M7 brief shortened it; the ERD does not.
 * A stable machine code is only stable if it is copied exactly.
 *
 * Never translated in the database (`CLAUDE.md` section 12). The interface renders
 * these through message keys.
 */
enum PropertyType: string
{
    case LAND = 'LAND';
    case LAND_AND_BUILDING = 'LAND_AND_BUILDING';
    case APARTMENT_UNIT = 'APARTMENT_UNIT';
    case OTHER = 'OTHER';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
