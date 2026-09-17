<?php

namespace App\Domains\Calendar\Enums;

enum CalendarEventType: string
{
    case APPOINTMENT = 'APPOINTMENT';
    case SIGNING = 'SIGNING';
    case DEADLINE = 'DEADLINE';
    case REMINDER = 'REMINDER';
    case INTERNAL_MEETING = 'INTERNAL_MEETING';
    case OTHER = 'OTHER';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
