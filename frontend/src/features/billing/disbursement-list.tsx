"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Skeleton } from "@/components/ui/skeleton";
import { AmountField } from "@/features/billing/amount-field";
import { billingQueryKeys, getDisbursements } from "@/services/billing";

/**
 * Costs the office carried for clients (M8.2, D-124).
 *
 * **No status column, because the table has none.** `disbursements.*` has no
 * lifecycle verb, so there is nothing for a status to mean — see the migration.
 *
 * A disbursement is a record, not a tax: nothing here computes a rate or gates on
 * one, which is the line that keeps O-040 intact.
 */
export function DisbursementList() {
  const t = useTranslations("billing");

  const query = useQuery({
    queryKey: billingQueryKeys.disbursements({}),
    queryFn: () => getDisbursements({}),
  });

  const disbursements = query.data?.data ?? [];

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

  if (disbursements.length === 0) {
    return <p className="text-muted-foreground text-sm">{t("noDisbursements")}</p>;
  }

  return (
    <div className="border-border overflow-x-auto rounded-lg border">
      <table className="w-full text-sm">
        <thead className="bg-muted/40 text-muted-foreground text-xs">
          <tr>
            <th className="px-3 py-2 text-left font-medium">{t("description")}</th>
            <th className="px-3 py-2 text-left font-medium">{t("incurredOn")}</th>
            <th className="px-3 py-2 text-left font-medium">{t("matter")}</th>
            <th className="px-3 py-2 text-left font-medium">{t("rebilledOn")}</th>
            <th className="px-3 py-2 text-right font-medium">{t("amount")}</th>
          </tr>
        </thead>

        <tbody className="divide-border divide-y">
          {disbursements.map((disbursement) => (
            <tr key={disbursement.id}>
              <td className="px-3 py-2">{disbursement.description}</td>
              <td className="px-3 py-2 tabular-nums">{disbursement.incurred_on ?? "—"}</td>
              <td className="px-3 py-2">{disbursement.matter?.reference ?? "—"}</td>
              <td className="px-3 py-2">{disbursement.invoice?.reference ?? "—"}</td>

              <td className="px-3 py-2 text-right">
                <AmountField
                  amount={disbursement.amount}
                  currency={disbursement.currency}
                  visible={disbursement.amounts_visible}
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
