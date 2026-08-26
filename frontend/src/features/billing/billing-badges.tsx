"use client";

import { useTranslations } from "next-intl";

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
const QUOTATION_TINT: Record<QuotationStatus, string> = {
  DRAFT: "border-border text-muted-foreground",
  APPROVED: "border-primary/30 text-primary",
};

const INVOICE_TINT: Record<InvoiceStatus, string> = {
  DRAFT: "border-border text-muted-foreground",
  ISSUED: "border-primary/30 text-primary",
  CANCELLED: "border-border text-muted-foreground line-through",
};

const PAYMENT_TINT: Record<PaymentStatus, string> = {
  PENDING: "border-border text-muted-foreground",
  VERIFIED: "border-primary/30 text-primary",
};

function Chip({ label, field, tint }: { label: string; field: string; tint: string }) {
  return (
    <span
      className={`shrink-0 rounded-full border px-2 py-0.5 text-xs ${tint}`}
      aria-label={`${field}: ${label}`}
    >
      {label}
    </span>
  );
}

export function QuotationStatusBadge({ status }: { status: QuotationStatus }) {
  const t = useTranslations("billing");

  return (
    <Chip
      label={t(`quotationStatuses.${status}`)}
      field={t("status")}
      tint={QUOTATION_TINT[status]}
    />
  );
}

export function InvoiceStatusBadge({ status }: { status: InvoiceStatus }) {
  const t = useTranslations("billing");

  return (
    <Chip label={t(`invoiceStatuses.${status}`)} field={t("status")} tint={INVOICE_TINT[status]} />
  );
}

export function PaymentStatusBadge({ status }: { status: PaymentStatus }) {
  const t = useTranslations("billing");

  return (
    <Chip label={t(`paymentStatuses.${status}`)} field={t("status")} tint={PAYMENT_TINT[status]} />
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

  return (
    <Chip label={t("overdue")} field={t("status")} tint="border-destructive/40 text-destructive" />
  );
}
