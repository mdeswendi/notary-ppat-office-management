"use client";

import { useTranslations } from "next-intl";

import { Badge, type BadgeTone } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import type { InvoiceStatus, PaymentStatus, QuotationStatus } from "@/types/billing";

/**
 * Status chips for the billing surfaces (M8.2).
 *
 * **Text carries the status; colour only reinforces it** (`CLAUDE.md` §49). Each
 * chip renders the translated label, so a reader who cannot distinguish the tints
 * loses nothing — and an `aria-label` names the field as well as the value.
 *
 * The palette stays inside the semantic tokens `04_UI_DESIGN_SYSTEM.md` defines.
 * §39 rules out the traffic-light treatment a status chip usually gets: this is a
 * professional office system, not a dashboard toy.
 */
const QUOTATION_TONE: Record<QuotationStatus, BadgeTone> = {
  DRAFT: "muted",
  APPROVED: "primarySubtle",
};

const INVOICE_TONE: Record<InvoiceStatus, BadgeTone> = {
  DRAFT: "muted",
  ISSUED: "primarySubtle",
  CANCELLED: "muted",
};

const PAYMENT_TONE: Record<PaymentStatus, BadgeTone> = {
  PENDING: "muted",
  VERIFIED: "primarySubtle",
};

/**
 * A billing chip: the shared Badge plus the one thing these rows need.
 *
 * `shrink-0` keeps the status readable when a long client name squeezes the row,
 * which is why these were never plain badges. The strike-through on a cancelled
 * invoice is a second cue on top of the label, not a replacement for it.
 */
function Chip({
  label,
  field,
  tone,
  className,
}: {
  label: string;
  field: string;
  tone: BadgeTone;
  className?: string;
}) {
  return (
    <Badge tone={tone} className={cn("shrink-0", className)} aria-label={`${field}: ${label}`}>
      {label}
    </Badge>
  );
}

export function QuotationStatusBadge({ status }: { status: QuotationStatus }) {
  const t = useTranslations("billing");

  return (
    <Chip
      label={t(`quotationStatuses.${status}`)}
      field={t("status")}
      tone={QUOTATION_TONE[status]}
    />
  );
}

export function InvoiceStatusBadge({ status }: { status: InvoiceStatus }) {
  const t = useTranslations("billing");

  return (
    <Chip
      label={t(`invoiceStatuses.${status}`)}
      field={t("status")}
      tone={INVOICE_TONE[status]}
      className={status === "CANCELLED" ? "line-through" : undefined}
    />
  );
}

export function PaymentStatusBadge({ status }: { status: PaymentStatus }) {
  const t = useTranslations("billing");

  return (
    <Chip label={t(`paymentStatuses.${status}`)} field={t("status")} tone={PAYMENT_TONE[status]} />
  );
}

/**
 * Late, and still owed something.
 *
 * **Computed on the server from `due_date`, never a stored status** (D-124), so
 * this renders a fact rather than a lifecycle state. Absent when the invoice is
 * not overdue: a chip saying "not overdue" on every row is noise.
 */
export function InvoiceOverdueBadge({ isOverdue }: { isOverdue: boolean }) {
  const t = useTranslations("billing");

  if (!isOverdue) {
    return null;
  }

  return <Chip label={t("overdue")} field={t("status")} tone="destructive" />;
}
