<?php

namespace App\Domains\Notary\Enums;

/**
 * The lifecycle state of a Notarial Deed (M6.1, D-120).
 *
 * Transcribed verbatim from `03_DATABASE_ERD.md` section 17. Six values, and no
 * seventh may be added: a status the canonical list does not name would be a
 * lifecycle rule invented here.
 *
 * ## Four are reachable, two are stored vocabulary
 *
 * ```text
 * create    ->  DRAFT
 * review    DRAFT         ->  UNDER_REVIEW    notary.deeds.review
 * approve   UNDER_REVIEW  ->  APPROVED        notary.deeds.approve
 * finalize  APPROVED      ->  FINALIZED       notary.deeds.finalize
 *
 * VOID        no path, no capability
 * SUPERSEDED  no path, no capability
 * ```
 *
 * **The ladder is not invented.** `CLAUDE.md` section 29 states it verbatim as the
 * legal-record lifecycle — `DRAFT → UNDER_REVIEW → APPROVED → FINALIZED → LOCKED` —
 * and section 64 states its consequence: once finalized, prevent normal edits and
 * preserve the original values. That is a constitution-level statement about legal
 * records generally, not content inferred from `08_NOTARY_WORKFLOW.md`, which is
 * `DRAFT — DOMAIN VALIDATION REQUIRED` and may not be implemented from.
 *
 * **`VOID` and `SUPERSEDED` are canonical vocabulary that nothing produces.**
 * `CLAUDE.md` section 29 lists `CORRECTION`, `AMENDMENT`, `SUPERSEDE` and `VOID` as
 * *"possible future correction mechanisms"* that *"must follow documented business
 * rules"*; `08_NOTARY_WORKFLOW.md` section 6 asks *"What correction mechanisms are
 * permitted after finalization?"* and has no answer; and the permission catalogue
 * contains neither `notary.deeds.void` nor `notary.deeds.lock` nor
 * `notary.deeds.delete`. Three sources agree, so the CHECK constraint admits all six
 * values and the API produces four.
 *
 * This is the D-109 pattern — Matter's `IN_PROGRESS`, `WAITING` and `ON_HOLD` are
 * stored vocabulary no control sets — and the reason it is a pattern rather than an
 * oversight: **the vocabulary is canonical even where the rule that reaches it is
 * not.** {@see unreachable()} makes the claim testable.
 *
 * **`LOCKED` is not a status.** Section 29's ladder ends there, but the ERD's status
 * list does not contain it: locking is recorded by `notary_deeds.locked_at`, a
 * separate column. Adding a seventh case would contradict the transcription.
 */
enum NotaryDeedStatus: string
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
     * The statuses some code path in M6 can actually produce.
     *
     * @return array<int, self>
     */
    public static function reachable(): array
    {
        return [self::DRAFT, self::UNDER_REVIEW, self::APPROVED, self::FINALIZED];
    }

    /**
     * Canonical vocabulary with no code path — see the class docblock.
     *
     * Exposed so a test can assert the list rather than trusting a comment, and so
     * the milestone that builds correction mechanisms has to change this function
     * deliberately rather than discovering the constraint by accident.
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

    /**
     * Settled: the deed has reached a state this milestone does not move it out of.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::FINALIZED, self::VOID, self::SUPERSEDED], true);
    }

    /**
     * May the deed's own fields still be edited?
     *
     * **Everything up to and including `APPROVED`**, which is the literal reading of
     * `CLAUDE.md` section 29: it denies normal updates *once finalized or locked*
     * and says nothing about approval.
     *
     * The narrower rule — that approval freezes the content, so an edit after
     * approval must re-open review — is the more familiar one and is **not encoded
     * here**, because no canonical document states it. It is exactly the kind of
     * approval requirement section 62 forbids inventing. An office that works that
     * way enforces it as practice until somebody writes it down.
     */
    public function isEditable(): bool
    {
        return ! $this->isSettled();
    }

    /**
     * May a deed number be recorded against a deed in this state?
     *
     * Any state the product can reach. **Deliberately not narrowed**: *"who assigns
     * the number, and when?"* is open question one, so tying numbering to a
     * lifecycle position — at creation, at approval, at finalization — would be
     * answering it. `notary.deeds.number` is its own capability precisely so the
     * office decides when to use it.
     */
    public function acceptsNumber(): bool
    {
        return true;
    }
}
