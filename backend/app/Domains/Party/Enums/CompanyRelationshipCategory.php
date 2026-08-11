<?php

namespace App\Domains\Party\Enums;

/**
 * The two authorization surfaces company relationships answer to (D-083).
 *
 * Not a stored value — nothing persists this. It exists so that the mapping from
 * `CompanyRelationshipType` to a permission family lives in one typed place
 * instead of being re-derived, slightly differently, wherever a check is written.
 *
 * The permission codes are the canonical ones already in the registry. M2.1 adds
 * none.
 */
enum CompanyRelationshipCategory: string
{
    case MANAGEMENT = 'MANAGEMENT';
    case OWNERSHIP = 'OWNERSHIP';

    public function viewPermission(): string
    {
        return match ($this) {
            self::MANAGEMENT => 'companies.management.view',
            self::OWNERSHIP => 'companies.shareholders.view',
        };
    }

    public function updatePermission(): string
    {
        return match ($this) {
            self::MANAGEMENT => 'companies.management.update',
            self::OWNERSHIP => 'companies.shareholders.update',
        };
    }
}
