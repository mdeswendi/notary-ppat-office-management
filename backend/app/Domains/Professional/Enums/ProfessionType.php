<?php

namespace App\Domains\Professional\Enums;

enum ProfessionType: string
{
    case NOTARY = 'NOTARY';
    case PPAT = 'PPAT';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
