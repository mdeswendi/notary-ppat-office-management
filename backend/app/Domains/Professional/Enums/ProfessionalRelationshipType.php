<?php

namespace App\Domains\Professional\Enums;

enum ProfessionalRelationshipType: string
{
    case INTERNAL = 'INTERNAL';
    case EXTERNAL = 'EXTERNAL';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
