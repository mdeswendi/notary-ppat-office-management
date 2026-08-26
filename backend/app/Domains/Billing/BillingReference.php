<?php

namespace App\Domains\Billing;

/**
 * Internal reference formats for quotations and invoices (M8.2, D-124).
 *
 * ```text
 * QUO-2026-000001
 * INV-2026-000001
 * ```
 *
 * **`CLAUDE.md` section 38's warning applies with full force: these are internal
 * application references.** Neither is a legal document number, neither is a deed
 * number, and neither may be presented as one. An invoice number is a commercial
 * identifier the office allocates for its own filing.
 *
 * ## The shape follows the lock, not the brief
 *
 * The M8.2 brief proposed `QTN-{YYYY}-{SEQ:5}`. The M8 lock's section 9.7 —
 * accepted at M8.0 — specifies `QUO-{YYYY}-{SEQ:6}`, and every one of the five
 * existing internal references in `03_DATABASE_ERD.md` section 27 pads to six.
 * A sixth pattern that padded to five would be the only one out of step, for no
 * stated reason.
 *
 * ## Not a parser
 *
 * {@see self::matchesFormat()} answers yes or no. It never hands back the year or
 * the sequence — the same refusal `DocumentReference` makes, because a reference
 * read back out of a string is a second source of truth for a number the
 * allocator already owns.
 */
class BillingReference
{
    public const QUOTATION = 'QUOTATION';

    public const INVOICE = 'INVOICE';

    private const MINIMUM_DIGITS = 6;

    /**
     * @var array<string, string>
     */
    private const PREFIXES = [
        self::QUOTATION => 'QUO',
        self::INVOICE => 'INV',
    ];

    public static function format(string $code, int $year, int $sequence): string
    {
        return sprintf(
            '%s-%04d-%s',
            self::prefixFor($code),
            $year,
            str_pad((string) $sequence, self::MINIMUM_DIGITS, '0', STR_PAD_LEFT),
        );
    }

    /**
     * Whether a string is shaped like this kind of billing reference.
     */
    public static function matchesFormat(string $code, string $candidate): bool
    {
        $prefix = self::prefixFor($code);

        return preg_match('/^'.$prefix.'-\d{4}-\d{'.self::MINIMUM_DIGITS.',}$/', $candidate) === 1;
    }

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(self::PREFIXES);
    }

    private static function prefixFor(string $code): string
    {
        return self::PREFIXES[$code]
            ?? throw new \InvalidArgumentException(
                "No billing reference prefix for [{$code}]. "
                .'Known codes: '.implode(', ', array_keys(self::PREFIXES)).'.'
            );
    }
}
