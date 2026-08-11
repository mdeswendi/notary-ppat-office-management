<?php

namespace App\Domains\Party\Enums;

/**
 * The legal form of an organization.
 *
 * Transcribed exactly from `03_DATABASE_ERD.md` section 6 — seven values, no
 * more. Indonesian legal forms are not invented here, and none is added because
 * it "seems obviously missing": `OTHER` exists precisely so that an unforeseen
 * form is recorded honestly rather than forced into the nearest wrong category.
 *
 * Stored as stable codes. `PT` is displayed as *Perseroan Terbatas* by the
 * interface, never stored that way (CLAUDE.md section 12).
 *
 * Carrying a value here asserts nothing about what that form legally requires —
 * no director count, no commissioner requirement, no capital rule. Those are
 * domain rules this milestone has no authority to invent (D-083).
 */
enum CompanyEntityType: string
{
    case PT = 'PT';
    case CV = 'CV';
    case YAYASAN = 'YAYASAN';
    case PERKUMPULAN = 'PERKUMPULAN';
    case KOPERASI = 'KOPERASI';
    case FIRMA = 'FIRMA';
    case OTHER = 'OTHER';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
