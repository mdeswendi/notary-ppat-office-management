<?php

namespace App\Domains\Ppat\Enums;

/**
 * The state of a Warkah bundle (M7.1, D-121).
 *
 * **Transcribed verbatim** from `03_DATABASE_ERD.md` section 19, which gives these
 * five explicitly. That is a real difference from `notary_minuta.release_status`,
 * which the ERD names and leaves empty — so Warkah gets a CHECK constraint and Minuta
 * did not.
 *
 * ## Three are reachable, two are not
 *
 * ```text
 * INCOMPLETE    the state a new bundle starts in
 * UNDER_REVIEW  ppat.warkah.update
 * COMPLETE      ppat.warkah.verify
 *
 * FINALIZED     ppat.warkah.finalize   registered, unimplemented
 * ARCHIVED      ppat.warkah.archive    registered, unimplemented
 * ```
 *
 * The last two are canonical values whose **trigger** is open question eight —
 * *"what are the binding/archiving requirements for deeds and supporting Warkah?"*
 * Both capabilities exist and both stay unimplemented until somebody answers it
 * (D-064, O-041), the same state `notary.minuta.archive` and `.release` are in.
 *
 * ## No status is derived from completeness, in either direction
 *
 * `COMPLETE` does not follow automatically from 100%, and 100% does not require
 * `COMPLETE`. **The percentage counts what the office listed; the status is what
 * somebody decided** — and which of the two governs legal sufficiency is precisely
 * what open question three does not answer. Coupling them would encode a rule out of
 * two facts that happen to sit in the same row.
 */
enum PpatWarkahStatus: string
{
    case INCOMPLETE = 'INCOMPLETE';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case COMPLETE = 'COMPLETE';
    case FINALIZED = 'FINALIZED';
    case ARCHIVED = 'ARCHIVED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return array<int, self>
     */
    public static function reachable(): array
    {
        return [self::INCOMPLETE, self::UNDER_REVIEW, self::COMPLETE];
    }

    /**
     * @return array<int, self>
     */
    public static function unreachable(): array
    {
        return [self::FINALIZED, self::ARCHIVED];
    }
}
