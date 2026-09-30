<?php

namespace App\Domains\Billing\Enums;

/**
 * Legacy storage values retained to read old quotation rows safely (D-137).
 *
 * These values no longer represent a user-facing or business lifecycle. New code
 * must not expose, filter, transition, or authorize quotations by this enum.
 */
enum QuotationStatus: string
{
    case DRAFT = 'DRAFT';
    case APPROVED = 'APPROVED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

}
