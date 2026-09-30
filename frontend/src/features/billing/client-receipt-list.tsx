"use client";

import { useState } from "react";
import { Fragment } from "react";
import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { EmptyState } from "@/components/feedback/empty-state";
import { DateText } from "@/components/i18n/date-text";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AmountField } from "@/features/billing/amount-field";
import { ClientReceiptForm } from "@/features/billing/client-receipt-form";
import { PermissionGuard } from "@/components/permission-guard";
import { billingQueryKeys, getClientReceipts } from "@/services/billing";
import type { ClientReceipt } from "@/types/billing";

export function ClientReceiptList() {
  const t = useTranslations("billing");
  const tActions = useTranslations("actions");
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<ClientReceipt | null>(null);
  const listQuery = { per_page: 100 } as const;
  const query = useQuery({
    queryKey: billingQueryKeys.clientReceipts(listQuery),
    queryFn: () => getClientReceipts(listQuery),
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true">
        <span className="sr-only">{t("loading")}</span>
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
      </div>
    );
  }

  if (query.isError) {
    return (
      <BaseErrorState
        title={t("listErrorTitle")}
        description={t("listUnavailable")}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  const receipts = query.data.data;

  return (
    <div className="flex flex-col gap-4">
      <PermissionGuard permission="client_receipts.create">
        <Button
          type="button"
          className="self-start"
          onClick={() => {
            setEditing(null);
            setCreating((value) => !value);
          }}
        >
          {creating ? tActions("cancel") : t("newClientReceipt")}
        </Button>
        {creating ? <ClientReceiptForm onDone={() => setCreating(false)} /> : null}
      </PermissionGuard>
      {receipts.length === 0 ? (
        <EmptyState title={t("emptyTitle")} description={t("noClientReceipts")} />
      ) : (
        <div className="border-border overflow-x-auto rounded-lg border">
          <table className="w-full min-w-[42rem] text-sm">
            <caption className="sr-only">{t("clientReceipts")}</caption>
            <thead className="bg-muted/40 text-muted-foreground text-xs">
              <tr>
                <th scope="col" className="px-3 py-2 text-left font-medium">
                  {t("receivedOn")}
                </th>
                <th scope="col" className="px-3 py-2 text-left font-medium">
                  {t("client")}
                </th>
                <th scope="col" className="px-3 py-2 text-left font-medium">
                  {t("paymentMethod")}
                </th>
                <th scope="col" className="px-3 py-2 text-right font-medium">
                  {t("amount")}
                </th>
                <th scope="col" className="px-3 py-2 text-left font-medium">
                  {t("recordedBy")}
                </th>
                <th scope="col" className="px-3 py-2 text-right font-medium">
                  {t("actions")}
                </th>
              </tr>
            </thead>
            <tbody className="divide-border divide-y">
              {receipts.map((receipt) => (
                <Fragment key={receipt.id}>
                  <tr>
                    <td className="px-3 py-2 whitespace-nowrap">
                      <DateText value={receipt.received_on} />
                    </td>
                    <td className="px-3 py-2">{receipt.client_party?.display_name ?? "—"}</td>
                    <td className="px-3 py-2">{t(`methods.${receipt.method_code}`)}</td>
                    <td className="px-3 py-2 text-right">
                      <AmountField
                        amount={receipt.amount}
                        currency={receipt.currency}
                        visible={receipt.amounts_visible}
                      />
                    </td>
                    <td className="px-3 py-2">{receipt.created_by?.name ?? "—"}</td>
                    <td className="px-3 py-2 text-right">
                      {receipt.capabilities?.can_update ? (
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => {
                            setCreating(false);
                            setEditing(editing?.id === receipt.id ? null : receipt);
                          }}
                        >
                          {editing?.id === receipt.id ? tActions("cancel") : tActions("edit")}
                        </Button>
                      ) : (
                        "—"
                      )}
                    </td>
                  </tr>
                  {editing?.id === receipt.id ? (
                    <tr>
                      <td colSpan={6} className="px-3 py-3">
                        <ClientReceiptForm receipt={receipt} onDone={() => setEditing(null)} />
                      </td>
                    </tr>
                  ) : null}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
