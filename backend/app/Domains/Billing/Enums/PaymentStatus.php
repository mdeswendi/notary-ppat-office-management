<?php

namespace App\Domains\Billing\Enums;

/**
 * Whether a recorded payment has been confirmed (M8.2, D-124, D-125).
 *
 * **Two states, read off two verbs** — `payments.create`, `payments.verify`:
 *
 * ```text
 * PENDING --verify--> VERIFIED
 * ```
 *
 * The M8.2 brief proposed `RECEIVED / VERIFIED / REJECTED`. **There is no
 * `payments.reject`** in the catalogue, and no `payments.update` and no delete
 * either — payments are the one billing surface the catalogue gives no
 * correction path at all (O-050).
 *
 * ## The verify gate is the control, and it is the only one
 *
 * **Only `VERIFIED` payments count toward anything.** A mis-entered payment
 * caught before verification affects no figure anywhere: not the invoice's
 * outstanding amount, not the dashboard, not a report. It stays visible and
 * uncounted rather than hidden, so somebody can see that it was entered.
 *
 * What has no remedy is a payment **verified** in error. M8.2 ships that honestly
 * rather than inventing an uncatalogued verb — the same disposition M7.3 gave
 * one-way property archiving (O-045), and the reason `payments` carries no
 * `deleted_at` and no `updated_by`.
 */
enum PaymentStatus: string
{
    case PENDING = 'PENDING';
    case VERIFIED = 'VERIFIED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isVerifiable(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Does this payment count toward what an invoice has been paid?
     */
    public function counts(): bool
    {
        return $this === self::VERIFIED;
    }
}
