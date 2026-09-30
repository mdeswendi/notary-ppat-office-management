"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { EmptyState } from "@/components/feedback/empty-state";
import { InlineAlert } from "@/components/feedback/inline-alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AmountField } from "@/features/billing/amount-field";
import { getRevenue, reportQueryKeys } from "@/services/reports";

/**
 * Actual client receipts by month (D-139).
 *
 * ## It shows nothing at all without `billing.amount.view`
 *
 * Every cell of this report is a sum. There is no non-monetary half to serve —
 * a "revenue report" of row counts would be a different report pretending to be
 * this one — so the server returns `data: null` and this renders a plain
 * explanation rather than an empty table.
 *
 * Revenue comes from the dedicated client receipt register. It does not include
 * quotations, invoice payments or money paid out for process costs.
 */
export function RevenueReport() {
  const t = useTranslations("reports");

  const query = useQuery({
    queryKey: reportQueryKeys.page("/api/v1/reports/financial/revenue", {}),
    queryFn: () => getRevenue(),
  });

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
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t("unavailable")}
        action={
          <Button variant="outline" size="sm" onClick={() => void query.refetch()}>
            {t("retry")}
          </Button>
        }
      />
    );
  }

  if (query.data.data === null) {
    return <InlineAlert tone="info">{t("revenueWithheld")}</InlineAlert>;
  }

  const rows = query.data.data;

  if (rows.length === 0) {
    return <EmptyState title={t("emptyTitle")} description={t("noData")} />;
  }

  return (
    <div className="border-border overflow-x-auto rounded-lg border">
      <table className="w-full min-w-[40rem] text-sm">
        <thead className="bg-muted/40 text-muted-foreground text-xs">
          <tr>
            <th scope="col" className="px-3 py-2 text-left font-medium">
              {t("columns.period")}
            </th>
            <th scope="col" className="px-3 py-2 text-right font-medium whitespace-nowrap">
              {t("columns.receipt_count")}
            </th>
            <th scope="col" className="px-3 py-2 text-right font-medium whitespace-nowrap">
              {t("columns.total_amount")}
            </th>
          </tr>
        </thead>

        <tbody className="divide-border divide-y">
          {rows.map((row) => (
            <tr key={row.period}>
              <td className="px-3 py-2 whitespace-nowrap tabular-nums">{row.period}</td>
              <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                {row.receipt_count}
              </td>
              <td className="px-3 py-2 text-right whitespace-nowrap">
                {/* Reaching here at all means the grant is held, so `visible` is
                    true — the server would have sent `null` otherwise. */}
                <AmountField amount={row.total_amount} currency="IDR" visible emphasis />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
