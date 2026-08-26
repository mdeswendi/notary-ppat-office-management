<?php

namespace App\Domains\Billing\Enums;

use App\Models\Invoice;

/**
 * The lifecycle state of an Invoice (M8.2, D-124).
 *
 * **Three states, read off four verbs** — `create`, `update`, `issue`, `cancel`:
 *
 * ```text
 * DRAFT --issue--> ISSUED --cancel--> CANCELLED
 * ```
 *
 * ## Settlement is not a lifecycle state
 *
 * The M8.2 brief proposed `PARTIALLY_PAID`, `PAID` and `OVERDUE` as statuses.
 * None of them is here, for two separate reasons, either sufficient.
 *
 * **Nothing authorizes setting them.** Recording a payment answers to
 * `payments.create` and `payments.verify`; neither is a verb on the invoice. An
 * invoice status that changed as a side effect of a payment would be a
 * transition no capability permits, and after `issue` the row is finalized —
 * `invoices.update` is a `DRAFT`-only act.
 *
 * **They are derived, and deriving them cannot drift.** How much has been paid is
 * the sum of the invoice's `VERIFIED` payments; whether it is settled is that sum
 * against the total; whether it is overdue is `due_date` against today. Stored,
 * `OVERDUE` would need a scheduled job to flip it and would be wrong every night
 * until the job ran. M5.4 settled this exact question for Task —
 * *"`isOverdue()` is computed, never stored"* — and the same answer applies here.
 *
 * So {@see Invoice} exposes `paid_amount`, `outstanding_amount`,
 * `isSettled()` and `isOverdue()`, all computed, and `status` says only what the
 * office deliberately did to the row.
 */
enum InvoiceStatus: string
{
    case DRAFT = 'DRAFT';
    case ISSUED = 'ISSUED';
    case CANCELLED = 'CANCELLED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * May an ordinary edit still change this invoice, or its lines?
     *
     * **`DRAFT` only.** Issuing is the finalization act: the invoice has been
     * sent to a client, so `CLAUDE.md` section 64 applies and the only remaining
     * act is `cancel`. This governs the line items too — editing what an issued
     * invoice charges for is editing the invoice.
     */
    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }

    public function isIssuable(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Cancelling is available before and after issue.
     *
     * A draft raised in error is cancelled rather than deleted — there is no
     * `invoices.delete` in the catalogue — and an issued invoice that should not
     * have gone out is cancelled rather than edited. Cancelling twice is refused.
     */
    public function isCancellable(): bool
    {
        return $this !== self::CANCELLED;
    }

    /**
     * Does this invoice still ask for money?
     *
     * A cancelled invoice is owed nothing whatever its total says, so it is
     * excluded from every outstanding figure.
     */
    public function isCollectable(): bool
    {
        return $this === self::ISSUED;
    }
}
