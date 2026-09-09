"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useState } from "react";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { EmptyState } from "@/components/feedback/empty-state";
import { DateText } from "@/components/i18n/date-text";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { AmountField } from "@/features/billing/amount-field";
import { InvoiceOverdueBadge, InvoiceStatusBadge } from "@/features/billing/billing-badges";
import { billingQueryKeys, getInvoices } from "@/services/billing";

/**
 * Invoices the caller may see (M8.2, D-124, D-125).
 *
 * **Every monetary column renders through `AmountField`**, which shows a
 * withheld placeholder when `billing.amount.view` is not held. The server has
 * already omitted the figures by then; this is presentation, not the control.
 *
 * **Overdue is a filter, not a status.** There is no `OVERDUE` state (D-124), so
 * "what is late" is `?overdue=true` against `due_date` — one question on one
 * surface (D-118).
 */
export function InvoiceList() {
  const t = useTranslations("billing");
  const tActions = useTranslations("actions");

  const [status, setStatus] = useState("");
  const [overdue, setOverdue] = useState(false);
  const [search, setSearch] = useState("");

  const query = useQuery({
    queryKey: billingQueryKeys.invoices({ status, overdue: overdue ? "true" : "", search }),
    queryFn: () => getInvoices({ status, overdue: overdue ? "true" : "", search }),
  });

  const invoices = query.data?.data ?? [];

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:flex lg:flex-wrap">
        <label className="flex min-w-0 flex-col gap-1 text-sm lg:flex-1">
          <span className="text-muted-foreground text-xs">{t("search")}</span>
          <Input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t("searchInvoicesPlaceholder")}
          />
        </label>

        <label className="flex min-w-0 flex-col gap-1 text-sm">
          <span className="text-muted-foreground text-xs">{t("status")}</span>
          <Select value={status} onChange={(event) => setStatus(event.target.value)}>
            <option value="">{t("allStatuses")}</option>
            {(["DRAFT", "ISSUED", "CANCELLED"] as const).map((value) => (
              <option key={value} value={value}>
                {t(`invoiceStatuses.${value}`)}
              </option>
            ))}
          </Select>
        </label>

        <label className="flex min-h-10 items-center gap-2 text-sm sm:col-span-2 lg:min-h-0">
          <Checkbox checked={overdue} onCheckedChange={(checked) => setOverdue(checked === true)} />
          {t("onlyOverdue")}
        </label>
      </div>

      {query.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </div>
      ) : query.isError ? (
        <BaseErrorState
          title={t("listErrorTitle")}
          description={t("listUnavailable")}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : invoices.length === 0 ? (
        <EmptyState title={t("emptyTitle")} description={t("noInvoices")} />
      ) : (
        <div className="border-border overflow-x-auto rounded-lg border">
          <table className="w-full min-w-[48rem] text-sm">
            <caption className="sr-only">{t("invoices")}</caption>
            <thead className="bg-muted/40 text-muted-foreground text-xs">
              <tr>
                <th scope="col" className="px-3 py-2 text-left font-medium">
                  {t("invoiceNumber")}
                </th>
                <th scope="col" className="px-3 py-2 text-left font-medium">
                  {t("client")}
                </th>
                <th scope="col" className="px-3 py-2 text-left font-medium">
                  {t("status")}
                </th>
                <th scope="col" className="px-3 py-2 text-left font-medium">
                  {t("dueDate")}
                </th>
                <th scope="col" className="px-3 py-2 text-right font-medium">
                  {t("total")}
                </th>
                <th scope="col" className="px-3 py-2 text-right font-medium">
                  {t("outstanding")}
                </th>
              </tr>
            </thead>

            <tbody className="divide-border divide-y">
              {invoices.map((invoice) => (
                <tr key={invoice.id}>
                  <td className="max-w-56 px-3 py-2">
                    <span className="font-medium whitespace-nowrap">{invoice.invoice_number}</span>
                    <div className="text-muted-foreground truncate text-xs">{invoice.title}</div>
                  </td>

                  <td className="px-3 py-2">{invoice.client_party?.display_name ?? "—"}</td>

                  <td className="px-3 py-2">
                    <div className="flex flex-wrap items-center gap-1">
                      <InvoiceStatusBadge status={invoice.status} />
                      <InvoiceOverdueBadge isOverdue={invoice.is_overdue} />
                    </div>
                  </td>

                  <td className="px-3 py-2 whitespace-nowrap">
                    <DateText value={invoice.due_date} />
                  </td>

                  <td className="px-3 py-2 text-right whitespace-nowrap">
                    <AmountField
                      amount={invoice.total_amount}
                      currency={invoice.currency}
                      visible={invoice.amounts_visible}
                    />
                  </td>

                  <td className="px-3 py-2 text-right whitespace-nowrap">
                    <AmountField
                      amount={invoice.outstanding_amount}
                      currency={invoice.currency}
                      visible={invoice.amounts_visible}
                      emphasis
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
