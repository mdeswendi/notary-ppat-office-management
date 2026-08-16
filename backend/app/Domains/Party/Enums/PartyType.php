<?php

namespace App\Domains\Party\Enums;

/**
 * Which subtype a Party is.
 *
 * Stable machine codes, never translated display strings (CLAUDE.md section 12,
 * D-078). `Perorangan` and `Perusahaan` are labels the interface renders from
 * these; they are not values the database ever holds.
 *
 * **Immutable after creation.** An Individual is never converted in place into a
 * Company. The two differ in identity semantics, validation, relationships, and
 * every future legal reference that will point at them, so an in-place change
 * would silently reinterpret existing data and anything already referring to it.
 *
 * That immutability is not left to good intentions. While a subtype row exists,
 * the composite foreign key `(party_id, party_type)` from `individuals` and
 * `companies` back to `parties (id, party_type)` makes the database refuse the
 * update outright — see the `parties` migration. `Party::booted()` refuses it a
 * second time, so the attempt fails before it reaches SQL and fails with a
 * message that says why.
 */
enum PartyType: string
{
    case INDIVIDUAL = 'INDIVIDUAL';
    case COMPANY = 'COMPANY';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
