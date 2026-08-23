<?php

namespace App\Domains\Document\Enums;

/**
 * The lifecycle state of a Document (M5.1, D-116; transitions added at M5.2, D-117).
 *
 * Transcribed verbatim from `03_DATABASE_ERD.md` section 13. Seven values, and no
 * eighth may be added: a status the canonical list does not name would be a
 * lifecycle rule invented here.
 *
 * ## M5.2 adds transitions, and the reversal is recorded rather than quiet
 *
 * M5.1 stated — following `15_M5_DOCUMENT_TASK_ARCHITECTURE.md` section 10.2 —
 * that **M5 encodes no transition matrix**. M5.2 encodes one, deliberately and by
 * decision (D-117), because the operational guards the milestone requires cannot
 * be expressed without one:
 *
 * ```text
 * upload   ->  RECEIVED
 * verify   RECEIVED, UNDER_REVIEW   ->  VERIFIED
 * archive  VERIFIED, FINAL          ->  ARCHIVED
 * delete   DRAFT, RECEIVED          ->  (soft deleted)
 * ```
 *
 * The three rules are **operational, not legal**. None of them says what a deed,
 * a Minuta or a Warkah may become — those are M6 and M7 and remain untouched.
 * What they say is that an office may not verify something twice, may not archive
 * what was never verified, and may not delete what somebody has already verified.
 * That last one is the point: `02_MENU_AND_PERMISSIONS.md` section 13 requires
 * `documents.delete` be *"heavily restricted"*, and "only before verification" is
 * the restriction.
 *
 * **`FINAL` and `VOID` are still unreachable, and that is stated rather than
 * implied** — the D-109 precedent. No capability in M5.2 sets either; both carry
 * legal weight no document in this repository defines, and guessing at it is what
 * `CLAUDE.md` section 62 prohibits. They appear in the rules above as *sources*
 * only, so an office that later gains a way to reach `FINAL` can archive from it
 * without this enum changing.
 *
 * **`DRAFT` and `UNDER_REVIEW` are likewise not produced by any M5.2 path.**
 * Upload creates `RECEIVED` — M5.1 created `DRAFT`, and had that continued,
 * verification would have been permanently unreachable, since nothing moves a
 * Document out of `DRAFT`. An endpoint that answers 422 to every document that
 * exists is worse than no endpoint. Both stay valid stored values and both stay
 * in the rules above, so a future submit-for-review step needs no change here.
 */
enum DocumentStatus: string
{
    case DRAFT = 'DRAFT';
    case RECEIVED = 'RECEIVED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case VERIFIED = 'VERIFIED';
    case FINAL = 'FINAL';
    case ARCHIVED = 'ARCHIVED';
    case VOID = 'VOID';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * A document awaiting a decision may be verified.
     */
    public function isVerifiable(): bool
    {
        return in_array($this, [self::RECEIVED, self::UNDER_REVIEW], true);
    }

    /**
     * Only a settled document is archived — archiving is how the office puts a
     * concluded record away, not a way to shelve an undecided one.
     */
    public function isArchivable(): bool
    {
        return in_array($this, [self::VERIFIED, self::FINAL], true);
    }

    /**
     * Deletion stops the moment somebody verifies.
     *
     * `CLAUDE.md` section 30 forbids user-facing hard delete for finalized legal
     * records and prefers a state; this is the soft-delete equivalent of the same
     * line — the record is removable while it is still just an uploaded file, and
     * not once it carries a verification.
     */
    public function isDeletable(): bool
    {
        return in_array($this, [self::DRAFT, self::RECEIVED], true);
    }

    /**
     * Whether `is_sensitive` is settled and may no longer be edited.
     *
     * Verification is the moment somebody looked at the document and accepted it
     * as what it claims to be, classification included. Changing the flag
     * afterwards would silently redefine which capability is needed to download a
     * file that has already been accepted.
     *
     * **`ARCHIVED` is included, and the spec named only `VERIFIED` and `FINAL`.**
     * Not an extension of the rule but the same rule applied consistently:
     * `ARCHIVED` is reachable *only* from those two, so leaving it out would mean
     * archiving a document unlocks a field verification had locked.
     */
    public function locksSensitivity(): bool
    {
        return in_array($this, [self::VERIFIED, self::FINAL, self::ARCHIVED], true);
    }
}
