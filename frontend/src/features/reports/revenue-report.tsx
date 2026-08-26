"use client";

import { useQuery } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";

import { Skeleton } from "@/components/ui/skeleton";
import { AmountField } from "@/features/billing/amount-field";
import { getRevenue, reportQueryKeys } from "@/services/reports";
import type { RevenueRow } from "@/types/reports";

/**
 * Verified receipts by month (M8.3, D-126, D-125).
 *
 * ## It shows nothing at all without `billing.amount.view`
 *
 * Every cell of this report is a sum. There is no non-monetary half to serve —
 * a "revenue report" of row counts would be a different report pretending to be
 * this one — so the server returns `data: null` and this renders a plain
 * explanation rather than an empty table.
 *
 * ## Revenue is money received, not money billed
 *
 * The server sums **verified** payments (O-050): a recorded-but-unverified
 * payment moves no figure anywhere, including here. Billed-but-unpaid work is a
 * different question, and the invoice report's outstanding column answers it.
 *
 * ## The service type name is chosen here, not in SQL
 *
 * Both names ship on every row. Picking one in a database aggregate would put a
 * presentation decision where no locale is known (`CLAUDE.md` sections 6, 10).
 */
export function RevenueReport() {
  const t = useTranslations("reports");
  const locale = useLocale();

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
    return <p className="text-muted-foreground text-sm">{t("unavailable")}</p>;
  }

  if (query.data.data === null) {
    return <p className="text-muted-foreground text-sm">{t("revenueWithheld")}</p>;
  }

  const rows = query.data.data;

  if (rows.length === 0) {
    return <p className="text-muted-foreground text-sm">{t("noData")}</p>;
  }

  return (
    <div className="border-border overflow-x-auto rounded-lg border">
      <table className="w-full text-sm">
        <thead className="bg-muted/40 text-muted-foreground text-xs">
          <tr>
            <th className="px-3 py-2 text-left font-medium">{t("columns.period")}</th>
            <th className="px-3 py-2 text-left font-medium">{t("columns.domain")}</th>
            <th className="px-3 py-2 text-left font-medium">{t("columns.service_type")}</th>
            <th className="px-3 py-2 text-right font-medium">{t("columns.payment_count")}</th>
            <th className="px-3 py-2 text-right font-medium">{t("columns.total_amount")}</th>
          </tr>
        </thead>

        <tbody className="divide-border divide-y">
          {rows.map((row, index) => (
            <tr key={`${row.period}-${row.domain ?? "none"}-${row.service_type_code ?? index}`}>
              <td className="px-3 py-2 tabular-nums">{row.period}</td>
              <td className="px-3 py-2">{row.domain ?? "—"}</td>
              <td className="px-3 py-2">{serviceTypeName(row, locale)}</td>
              <td className="px-3 py-2 text-right tabular-nums">{row.payment_count}</td>
              <td className="px-3 py-2 text-right">
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

/**
 * The service type's name in the reader's language, falling back to the code.
 *
 * A payment against an invoice with no Matter has no service type at all, and it
 * still belongs in the total — the server puts it in an unlabelled bucket rather
 * than dropping it, so this renders a dash rather than hiding the row.
 */
function serviceTypeName(row: RevenueRow, locale: string): string {
  const name = locale === "en" ? row.service_type_name_en : row.service_type_name_id;

  return name ?? row.service_type_code ?? "—";
}
