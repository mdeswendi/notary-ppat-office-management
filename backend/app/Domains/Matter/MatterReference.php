<?php

namespace App\Domains\Matter;

use App\Domains\Matter\Enums\MatterDomain;

/**
 * How a Matter internal reference is written down (M4.3, D-103).
 *
 * ```text
 * N-2026-000001     Notary
 * P-2026-000001     PPAT
 * ```
 *
 * **Ordinary office identification, and nothing more.** Not a deed number, not a
 * repertorium number, not a minuta or Warkah number, not a PPAT register entry,
 * not a land or government registration number, and not an entry in any legal
 * register. The `N` and `P` prefixes have no Notary or PPAT legal meaning — they
 * are a human-readable hint about which kind of work you are looking at, in a
 * system that already holds several kinds.
 *
 * **The sequence is not evidence of anything.** Gaps may exist, and the number is
 * not a count of records: an allocation that is committed and then not used
 * leaves a permanent gap by design, because the alternative is either reusing
 * references or serializing every create behind one lock. Nothing may infer how
 * many Matters an office has from the highest reference it has issued, and
 * sequential appearance carries no legal weight whatsoever.
 *
 * **The prefix map lives here, not on {@see MatterDomain}.** The enum answers what
 * a domain *is* and which capability namespace authorizes it; how a reference is
 * spelled is presentation, and putting it on the enum would make an authorization
 * type carry formatting concerns.
 *
 * Formatting only. This class allocates nothing and reads no database — see
 * {@see AllocateMatterReference}.
 */
final class MatterReference
{
    /**
     * Not legal codes. See the class docblock.
     */
    private const PREFIXES = [
        MatterDomain::NOTARY->value => 'N',
        MatterDomain::PPAT->value => 'P',
    ];

    /**
     * The minimum width of the sequence part.
     *
     * A **minimum**, not a maximum. The 1 000 000th Matter in one
     * Office-year-domain formats as seven digits rather than wrapping to `000000`
     * or being truncated — either of which would silently break uniqueness, which
     * is the one property an identifier must not lose. The column is sized for it.
     */
    public const SEQUENCE_DIGITS = 6;

    /**
     * The domain prefix, with no fallback.
     *
     * `match` is exhaustive over the enum, so a new domain case would be a
     * compile-time-ish failure here rather than a silently prefix-less reference.
     */
    public static function prefix(MatterDomain $domain): string
    {
        return self::PREFIXES[$domain->value];
    }

    /**
     * `N-2026-000001` from a domain, a year, and a sequence value.
     *
     * Deterministic and total: same inputs, same string, always.
     */
    public static function format(MatterDomain $domain, int $year, int $sequence): string
    {
        return sprintf(
            '%s-%04d-%s',
            self::prefix($domain),
            $year,
            str_pad((string) $sequence, self::SEQUENCE_DIGITS, '0', STR_PAD_LEFT),
        );
    }

    /**
     * Whether a string is shaped like a Matter reference, optionally of one
     * specific domain.
     *
     * For assertions and defensive checks. **Deliberately not a parser**: nothing
     * in the product may read the year, the sequence, or the domain back out of a
     * formatted reference. The year and domain are decided at allocation — from
     * the canonical clock and from the caller's context — and stored on the
     * counter row; re-deriving either from a string would make displayed text an
     * input to logic, which is how a cosmetic change becomes a behavioural one.
     */
    public static function matchesFormat(string $value, ?MatterDomain $domain = null): bool
    {
        $prefix = $domain === null
            ? implode('|', array_values(self::PREFIXES))
            : preg_quote(self::prefix($domain), '/');

        return preg_match('/^('.$prefix.')-\d{4}-\d{'.self::SEQUENCE_DIGITS.',}$/', $value) === 1;
    }
}
