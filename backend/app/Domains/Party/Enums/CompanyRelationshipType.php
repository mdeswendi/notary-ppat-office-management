<?php

namespace App\Domains\Party\Enums;

/**
 * How an Individual relates to a Company.
 *
 * The five values from `03_DATABASE_ERD.md` section 6, transcribed rather than
 * extended.
 *
 * Each maps to one of two authorization surfaces (D-083). The split categorises
 * by what the relationship is *about* — who acts for the organization versus who
 * owns it — and invents no Indonesian corporate law:
 *
 *   management  DIRECTOR, COMMISSIONER, AUTHORIZED_PERSON
 *   ownership   SHAREHOLDER, BENEFICIAL_OWNER
 *
 * Ownership data is not visible merely because somebody may view ordinary
 * Company details, which is why the two categories answer to different
 * permissions.
 *
 * **No cardinality is implied.** Nothing here requires a company to have a
 * director, forbids it from having several, demands a commissioner, makes
 * shareholdings total 100%, or determines beneficial ownership. Those are legal
 * rules, and M2 has no authority to invent them.
 */
enum CompanyRelationshipType: string
{
    case DIRECTOR = 'DIRECTOR';
    case COMMISSIONER = 'COMMISSIONER';
    case SHAREHOLDER = 'SHAREHOLDER';
    case AUTHORIZED_PERSON = 'AUTHORIZED_PERSON';
    case BENEFICIAL_OWNER = 'BENEFICIAL_OWNER';

    /**
     * Which authorization surface governs this relationship (D-083).
     */
    public function category(): CompanyRelationshipCategory
    {
        return match ($this) {
            self::DIRECTOR, self::COMMISSIONER, self::AUTHORIZED_PERSON => CompanyRelationshipCategory::MANAGEMENT,
            self::SHAREHOLDER, self::BENEFICIAL_OWNER => CompanyRelationshipCategory::OWNERSHIP,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
