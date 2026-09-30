"use client";

import { useEffect, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { isAxiosError } from "axios";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { EmptyState } from "@/components/feedback/empty-state";
import { DateText } from "@/components/i18n/date-text";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AmountField } from "@/features/billing/amount-field";
import { QuotationCreateForm } from "@/features/billing/quotation-create-form";
import { QuotationDetail } from "@/features/billing/quotation-detail";
import { billingQueryKeys, getQuotations } from "@/services/billing";

/**
 * Quotations the caller may see (M8.2, D-124).
 *
 * A quotation is an internal office price record. It has no client-approval
 * status and no billed/invoiced indicator; late or unforeseen costs are added as
 * additional detail lines by an authorized Principal.
 */
export function QuotationList() {
  const t = useTranslations("billing");
  const tActions = useTranslations("actions");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const detailRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (selectedId) detailRef.current?.scrollIntoView?.({ block: "start" });
  }, [selectedId]);

  const query = useQuery({
    queryKey: billingQueryKeys.quotations({}),
    queryFn: () => getQuotations({}),
  });

  const quotations = query.data?.data ?? [];

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-4">
        <QuotationCreateForm />
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </div>
      </div>
    );
  }

  if (query.isError) {
    const accessDenied = isAxiosError(query.error) && query.error.response?.status === 403;
    return (
      <div className="flex flex-col gap-4">
        <QuotationCreateForm />
        <BaseErrorState
          title={accessDenied ? t("quotationAccessTitle") : t("listErrorTitle")}
          description={accessDenied ? t("quotationAccessHint") : t("listUnavailable")}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      </div>
    );
  }

  if (quotations.length === 0) {
    return (
      <div className="flex flex-col gap-4">
        <QuotationCreateForm />
        <EmptyState title={t("emptyTitle")} description={t("noQuotations")} />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <QuotationCreateForm />
      {selectedId ? (
        <div ref={detailRef} className="scroll-mt-4">
          <QuotationDetail key={selectedId} id={selectedId} onClose={() => setSelectedId(null)} />
        </div>
      ) : null}
      <div className="border-border overflow-x-auto rounded-lg border">
        <table className="w-full min-w-[40rem] text-sm">
          <caption className="sr-only">{t("quotations")}</caption>
          <thead className="bg-muted/40 text-muted-foreground text-xs">
            <tr>
              <th scope="col" className="px-3 py-2 text-left font-medium">
                {t("quotationNumber")}
              </th>
              <th scope="col" className="px-3 py-2 text-left font-medium">
                {t("client")}
              </th>
              <th scope="col" className="px-3 py-2 text-left font-medium">
                {t("validUntil")}
              </th>
              <th scope="col" className="px-3 py-2 text-right font-medium">
                {t("total")}
              </th>
              <th scope="col" className="px-3 py-2 text-right font-medium">
                {t("actions")}
              </th>
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

                <td className="px-3 py-2 whitespace-nowrap">
                  <DateText value={quotation.valid_until} />
                </td>

                <td className="px-3 py-2 text-right">
                  <AmountField
                    amount={quotation.total_amount}
                    currency={quotation.currency}
                    visible={quotation.amounts_visible}
                  />
                </td>

                <td className="px-3 py-2 text-right">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setSelectedId(quotation.id)}
                  >
                    {t("viewQuotation")}
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
