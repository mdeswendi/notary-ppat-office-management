<?php

namespace App\Domains\Billing\Enums;

/**
 * How money arrived (M8.2).
 *
 * **This application's list, not a transcription** — like every other billing
 * value, since the ERD defines no billing schema (O-049). Four values, kept
 * deliberately coarse: the office needs to tell a bank transfer from cash when
 * reconciling, and does not need this software to model a payment industry.
 *
 * `OTHER` exists so an unusual method is recordable without a migration, and
 * `reference` on the payment row carries whatever identifies it.
 *
 * **Nothing branches on this value.** No rule gates on the method, no figure is
 * computed from it, and no integration reads it — M8.2 records payments and does
 * not process them. It exists to be filtered and displayed.
 */
enum PaymentMethod: string
{
    case CASH = 'CASH';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case CARD = 'CARD';
    case OTHER = 'OTHER';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
