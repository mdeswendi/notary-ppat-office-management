<?php

namespace App\Domains\Project;

/**
 * How a Project internal reference is written down (M3.2, D-094).
 *
 * ```text
 * PRJ-2026-000001
 * ```
 *
 * **Ordinary office identification, and nothing more.** Not a deed number, not a
 * repertorium number, not a Matter or Warkah number, not a land or government
 * registration number, and not an entry in any legal register. The `PRJ` prefix
 * has no Notary or PPAT legal meaning — it is a human-readable hint about which
 * kind of record you are looking at, in a system that will eventually hold
 * several.
 *
 * **The sequence is not evidence of anything.** Gaps may exist, and the number is
 * not a count of records: an allocation that is handed out and then not used —
 * a failed create, a rolled-back transaction after the counter moved — leaves a
 * gap by design, because the alternative is either reusing numbers or serializing
 * every create behind one lock. Nothing may infer how many Projects an office has
 * from the highest reference it has issued, and sequential appearance carries no
 * legal weight whatsoever.
 *
 * Formatting only. This class allocates nothing and reads no database — see
 * {@see AllocateProjectReference}.
 */
final class ProjectReference
{
    /**
     * Not a legal code. See the class docblock.
     */
    public const PREFIX = 'PRJ';

    /**
     * The minimum width of the sequence part.
     *
     * A **minimum**, not a maximum. The 999 999th Project in one Office-year
     * formats as seven digits rather than wrapping to `000000` or being
     * truncated — either of which would silently break uniqueness, which is the
     * one property an identifier must not lose. The column is sized for it.
     */
    public const SEQUENCE_DIGITS = 6;

    /**
     * `PRJ-2026-000001` from a year and a sequence value.
     *
     * Deterministic and total: same inputs, same string, always.
     */
    public static function format(int $year, int $sequence): string
    {
        return sprintf(
            '%s-%04d-%s',
            self::PREFIX,
            $year,
            str_pad((string) $sequence, self::SEQUENCE_DIGITS, '0', STR_PAD_LEFT),
        );
    }

    /**
     * Whether a string is shaped like a Project reference.
     *
     * For assertions and defensive checks. **Deliberately not a parser**: nothing
     * in the product may read the year or the sequence back out of a formatted
     * reference. The year is decided at allocation from the canonical clock and
     * stored on the counter row; re-deriving it from a string would make the
     * displayed text an input to logic, which is how a cosmetic change becomes a
     * behavioural one.
     */
    public static function matchesFormat(string $value): bool
    {
        return preg_match('/^'.self::PREFIX.'-\d{4}-\d{'.self::SEQUENCE_DIGITS.',}$/', $value) === 1;
    }
}
