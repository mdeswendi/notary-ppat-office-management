<?php

namespace App\Domains\Office\Enums;

enum OfficePracticeType: string
{
    case PPAT = 'PPAT';
    case NOTARY = 'NOTARY';
    case NOTARY_AND_PPAT = 'NOTARY_AND_PPAT';
    case COLLABORATION = 'COLLABORATION';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
