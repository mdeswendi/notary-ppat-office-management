<?php

namespace App\Domains\Billing\Enums;

/**
 * The lifecycle state of a Quotation (M8.2, D-124).
 *
 * **Read off the catalogue's verbs, not designed.** `quotations.*` carries four
 * codes — `view`, `create`, `update`, `approve` — of which exactly one is a
 * lifecycle verb. Two states follow, and no third can:
 *
 * ```text
 * DRAFT --approve--> APPROVED
 * ```
 *
 * The M8.2 brief proposed six: `DRAFT SENT ACCEPTED REJECTED EXPIRED CONVERTED`.
 * Verified against the live registry, **there is no `quotations.send`,
 * `.reject`, `.expire` or `.convert`**, and the brief's own constraint forbade
 * adding permissions. Storing a state nothing can set is the D-109 pattern this
 * project records as a cost rather than repeats as a design.
 *
 * So a quotation that comes to nothing **stays `DRAFT`**. That is not a gap
 * pretending to be a feature — it is the honest record of what the office
 * actually knows, which is that the offer was made and never approved.
 *
 * **Converting is not lost.** Turning an approved quotation into a bill is
 * `POST /api/v1/invoices` with a `quotation_id`, answering to the canonical
 * `invoices.create`. The link lives on the invoice.
 */
enum QuotationStatus: string
{
    case DRAFT = 'DRAFT';
    case APPROVED = 'APPROVED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * May an ordinary edit still change this quotation?
     *
     * **`DRAFT` only.** Approving is the finalization act: the figures have been
     * agreed, and `CLAUDE.md` section 64's discipline for finalized records
     * applies — the row displays read-only and its values are preserved.
     */
    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }

    public function isApprovable(): bool
    {
        return $this === self::DRAFT;
    }
}
