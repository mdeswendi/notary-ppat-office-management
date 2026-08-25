<?php

namespace App\Domains\Ppat\Enums;

/**
 * The lifecycle state of a PPAT Deed (M7.1, D-121).
 *
 * ## This vocabulary is a decision, not a transcription
 *
 * `03_DATABASE_ERD.md` section 18 gives `ppat_deeds` a `status` column and **no
 * values for it**, where section 17 gives `notary_deeds` six. M7 adopts the same six
 * so the two domains answer the same question the same way, on the authority of
 * `CLAUDE.md` section 29 — which states `DRAFT -> UNDER_REVIEW -> APPROVED ->
 * FINALIZED -> LOCKED` as the legal-record lifecycle generally, and section 64 its
 * consequence.
 *
 * **A later milestone that finds a canonical PPAT status list must reconcile with
 * this rather than assume it confirms it.** That is the whole reason the distinction
 * is written down here instead of left to look like transcription.
 *
 * ## Four are reachable, two are stored vocabulary
 *
 * ```text
 * create    ->  DRAFT
 * review    DRAFT         ->  UNDER_REVIEW    ppat.deeds.review
 * approve   UNDER_REVIEW  ->  APPROVED        ppat.deeds.approve
 * finalize  APPROVED      ->  FINALIZED       ppat.deeds.finalize
 *
 * VOID        no path, no capability
 * SUPERSEDED  no path, no capability
 * ```
 *
 * `VOID` and `SUPERSEDED` are the post-finalization correction mechanisms
 * `CLAUDE.md` section 29 requires documented business rules for; open question nine
 * in `09_PPAT_WORKFLOW.md` section 6 asks what they are, and the catalogue contains
 * no `ppat.deeds.void` or `ppat.deeds.lock`. Three sources agree, so the CHECK admits
 * six values and the API will produce four (D-109 pattern, O-039).
 *
 * **`LOCKED` is not a status.** Section 29's ladder ends there, but locking is
 * recorded by `ppat_deeds.locked_at`, a separate column — the same shape
 * `notary_deeds` has.
 */
enum PpatDeedStatus: string
{
    case DRAFT = 'DRAFT';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case APPROVED = 'APPROVED';
    case FINALIZED = 'FINALIZED';
    case VOID = 'VOID';
    case SUPERSEDED = 'SUPERSEDED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The statuses some code path can actually produce.
     *
     * @return array<int, self>
     */
    public static function reachable(): array
    {
        return [self::DRAFT, self::UNDER_REVIEW, self::APPROVED, self::FINALIZED];
    }

    /**
     * Canonical vocabulary with no code path.
     *
     * Exposed so a test asserts the list rather than trusting a comment, and so the
     * milestone that builds correction mechanisms has to change this deliberately.
     *
     * @return array<int, self>
     */
    public static function unreachable(): array
    {
        return [self::VOID, self::SUPERSEDED];
    }

    public function isReviewable(): bool
    {
        return $this === self::DRAFT;
    }

    public function isApprovable(): bool
    {
        return $this === self::UNDER_REVIEW;
    }

    public function isFinalizable(): bool
    {
        return $this === self::APPROVED;
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::FINALIZED, self::VOID, self::SUPERSEDED], true);
    }

    /**
     * May the deed be edited?
     *
     * Everything up to and including `APPROVED` — the literal reading of
     * `CLAUDE.md` section 29, which denies normal updates *once finalized* and says
     * nothing about approval. The narrower rule that approval freezes content is the
     * more familiar one and is deliberately not encoded here, because no canonical
     * document states it and it is exactly the kind of approval requirement section
     * 62 forbids inventing. The M6.1 ruling for Notary, unchanged.
     */
    public function isEditable(): bool
    {
        return ! $this->isSettled();
    }
}
