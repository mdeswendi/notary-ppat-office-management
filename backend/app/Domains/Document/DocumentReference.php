<?php

namespace App\Domains\Document;

/**
 * How an internal document reference is written (M5.1, D-116).
 *
 * ```text
 * DOC-2026-000001
 * ```
 *
 * **A formatter, not a parser.** It exposes `format` and `matchesFormat`,
 * allocates nothing, and reads no database. **Nothing may read the year or the
 * sequence back out of a formatted reference** — that would make displayed text
 * an input to logic, and the moment it does, changing the display format becomes
 * a breaking change. The year and sequence are columns and parameters; the string
 * is for people. This is `MatterReference`'s rule (D-108) one domain across.
 *
 * **Six digits are a minimum, not a maximum.** The 1 000 000th document in one
 * Office-year formats as seven digits rather than wrapping to `000000` or
 * truncating — either of which would silently break uniqueness, the one property
 * an identifier may not lose. `varchar(32)` is sized for it.
 *
 * **Never a legal number.** Not a deed number, not a repertorium entry, not a
 * minuta or Warkah number (`CLAUDE.md` section 38).
 */
class DocumentReference
{
    public const PREFIX = 'DOC';

    private const MINIMUM_DIGITS = 6;

    public static function format(int $year, int $sequence): string
    {
        return sprintf(
            '%s-%04d-%s',
            self::PREFIX,
            $year,
            str_pad((string) $sequence, self::MINIMUM_DIGITS, '0', STR_PAD_LEFT),
        );
    }

    /**
     * Whether a string is shaped like a document reference.
     *
     * Used for validation and for tests. **This is not a parser**: it answers yes
     * or no and never hands back the year or the sequence.
     */
    public static function matchesFormat(string $candidate): bool
    {
        return preg_match('/^'.self::PREFIX.'-\d{4}-\d{'.self::MINIMUM_DIGITS.',}$/', $candidate) === 1;
    }
}
