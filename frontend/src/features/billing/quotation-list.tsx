"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Skeleton } from "@/components/ui/skeleton";
import { AmountField } from "@/features/billing/amount-field";
import { QuotationStatusBadge } from "@/features/billing/billing-badges";
import { billingQueryKeys, getQuotations } from "@/services/billing";

/**
 * Quotations the caller may see (M8.2, D-124).
 *
 * **Two statuses, not six.** `quotations.approve` is the only lifecycle verb the
 * catalogue gives, so a quotation is either `DRAFT` or `APPROVED` — an offer that
 * came to nothing stays `DRAFT`, which is the honest record of what the office
 * knows.
 *
 * `invoices_count` answers the question this list is actually scanned for —
 * whether an agreed offer has been billed yet — and discloses no money.
 */
export function QuotationList() {
  const t = useTranslations("billing");

  const query = useQuery({
    queryKey: billingQueryKeys.quotations({}),
    queryFn: () => getQuotations({}),
  });

  const quotations = query.data?.data ?? [];

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
      </div>
    );
  }

  if (query.isError) {
    return <p className="text-muted-foreground text-sm">{t("listUnavailable")}</p>;
  }

  if (quotations.length === 0) {
    return <p className="text-muted-foreground text-sm">{t("noQuotations")}</p>;
  }

  return (
    <div className="border-border overflow-x-auto rounded-lg border">
      <table className="w-full text-sm">
        <thead className="bg-muted/40 text-muted-foreground text-xs">
          <tr>
            <th className="px-3 py-2 text-left font-medium">{t("quotationNumber")}</th>
            <th className="px-3 py-2 text-left font-medium">{t("client")}</th>
            <th className="px-3 py-2 text-left font-medium">{t("status")}</th>
            <th className="px-3 py-2 text-left font-medium">{t("validUntil")}</th>
            <th className="px-3 py-2 text-right font-medium">{t("total")}</th>
            <th className="px-3 py-2 text-right font-medium">{t("invoiced")}</th>
          </tr>
        </thead>

        <tbody className="divide-border divide-y">
          {quotations.map((quotation) => (
            <tr key={quotation.id}>
              <td className="px-3 py-2">
                <span className="font-medium">{quotation.quotation_number}</span>
                <div className="text-muted-foreground truncate text-xs">{quotation.title}</div>
              </td>

              <td className="px-3 py-2">{quotation.client_party?.display_name ?? "—"}</td>

              <td className="px-3 py-2">
                <QuotationStatusBadge status={quotation.status} />
              </td>

              <td className="px-3 py-2 tabular-nums">{quotation.valid_until ?? "—"}</td>

              <td className="px-3 py-2 text-right">
                <AmountField
                  amount={quotation.total_amount}
                  currency={quotation.currency}
                  visible={quotation.amounts_visible}
                />
              </td>

              <td className="px-3 py-2 text-right tabular-nums">{quotation.invoices_count ?? 0}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
