<?php

namespace App\Domains\Activity\Enums;

/**
 * What happened, in the vocabulary users read (M8.1, D-123).
 *
 * `03_DATABASE_ERD.md` section 24 gives four examples rather than a closed list:
 *
 * ```text
 * DOCUMENT_UPLOADED
 * MATTER_STAGE_CHANGED
 * TASK_COMPLETED
 * DEED_APPROVED
 * ```
 *
 * All four are here verbatim. The rest follow their shape — `RESOURCE_VERB`, past
 * tense — and are **this application's list rather than a transcribed one**, which
 * is why no CHECK constraint backs it. Examples in a canonical document are a
 * pattern to follow, not a vocabulary to complete: a fifth value invents nothing
 * legal, where a fifth `MatterStatus` would.
 *
 * **Every case here is a business milestone somebody would want to see on a
 * timeline.** Field-level corrections are not: they go to `audit_logs` with their
 * old and new values and stay out of the feed, because a timeline that reports
 * every typo fix is a timeline nobody reads.
 *
 * Each case pairs with a translation key under `activity.types.*` on the frontend.
 * The label is never stored — `CLAUDE.md` section 12.
 */
enum ActivityType: string
{
    case PROJECT_CREATED = 'PROJECT_CREATED';
    case MATTER_CREATED = 'MATTER_CREATED';
    case MATTER_STAGE_CHANGED = 'MATTER_STAGE_CHANGED';
    case DOCUMENT_UPLOADED = 'DOCUMENT_UPLOADED';
    case DOCUMENT_VERIFIED = 'DOCUMENT_VERIFIED';
    case TASK_CREATED = 'TASK_CREATED';
    case TASK_ASSIGNED = 'TASK_ASSIGNED';
    case TASK_COMPLETED = 'TASK_COMPLETED';
    case DEED_CREATED = 'DEED_CREATED';
    case DEED_REVIEWED = 'DEED_REVIEWED';
    case DEED_APPROVED = 'DEED_APPROVED';
    case DEED_FINALIZED = 'DEED_FINALIZED';
    case PROPERTY_CREATED = 'PROPERTY_CREATED';
    case WARKAH_VERIFIED = 'WARKAH_VERIFIED';

    // Billing (M8.2). Eight more of the same shape — a resource and a past-tense
    // verb — added because each is a business milestone somebody on the timeline
    // would want to see. Field corrections to a draft invoice are not here: they
    // go to `audit_logs` with their old and new values, per D-128.
    case QUOTATION_CREATED = 'QUOTATION_CREATED';
    case QUOTATION_APPROVED = 'QUOTATION_APPROVED';
    case INVOICE_CREATED = 'INVOICE_CREATED';
    case INVOICE_ISSUED = 'INVOICE_ISSUED';
    case INVOICE_CANCELLED = 'INVOICE_CANCELLED';
    case PAYMENT_RECORDED = 'PAYMENT_RECORDED';
    case PAYMENT_VERIFIED = 'PAYMENT_VERIFIED';
    case DISBURSEMENT_RECORDED = 'DISBURSEMENT_RECORDED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The translation key the frontend resolves for this entry.
     */
    public function descriptionKey(): string
    {
        return 'activity.types.'.$this->value;
    }
}
