"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AmountField } from "@/features/billing/amount-field";
import { PaymentStatusBadge } from "@/features/billing/billing-badges";
import { billingQueryKeys, getPayments, verifyPayment } from "@/services/billing";

/**
 * Payments the office has recorded (M8.2, D-124, O-050).
 *
 * **Verifying is a one-way door.** Only verified payments count toward an
 * invoice's paid total, and nothing undoes it: the catalogue gives payments no
 * update, delete or reject. The confirmation copy says so, because a control
 * whose consequence is irreversible must announce that before it is used, not
 * after.
 *
 * `can_verify` comes from the server and already combines capability with state,
 * so the button is absent on a payment that is already through the door.
 */
export function PaymentList() {
  const t = useTranslations("billing");
  const client = useQueryClient();

  const query = useQuery({
    queryKey: billingQueryKeys.payments({}),
    queryFn: () => getPayments({}),
  });

  const verify = useMutation({
    mutationFn: (id: string) => verifyPayment(id),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: billingQueryKeys.all() });
    },
  });

  const payments = query.data?.data ?? [];

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

  if (payments.length === 0) {
    return <p className="text-muted-foreground text-sm">{t("noPayments")}</p>;
  }

  return (
    <div className="flex flex-col gap-3">
      {verify.isError ? <p className="text-destructive text-sm">{t("verifyFailed")}</p> : null}

      <div className="border-border overflow-x-auto rounded-lg border">
        <table className="w-full text-sm">
          <thead className="bg-muted/40 text-muted-foreground text-xs">
            <tr>
              <th className="px-3 py-2 text-left font-medium">{t("invoice")}</th>
              <th className="px-3 py-2 text-left font-medium">{t("paidAt")}</th>
              <th className="px-3 py-2 text-left font-medium">{t("method")}</th>
              <th className="px-3 py-2 text-left font-medium">{t("status")}</th>
              <th className="px-3 py-2 text-right font-medium">{t("amount")}</th>
              <th className="px-3 py-2" />
            </tr>
          </thead>

          <tbody className="divide-border divide-y">
            {payments.map((payment) => (
              <tr key={payment.id}>
                <td className="px-3 py-2">
                  {payment.invoice ? (
                    <span className="font-medium">{payment.invoice.reference}</span>
                  ) : (
                    "—"
                  )}
                </td>

                <td className="px-3 py-2 tabular-nums">{payment.paid_at ?? "—"}</td>

                <td className="px-3 py-2">{t(`methods.${payment.method_code}`)}</td>

                <td className="px-3 py-2">
                  <PaymentStatusBadge status={payment.status} />
                </td>

                <td className="px-3 py-2 text-right">
                  <AmountField
                    amount={payment.amount}
                    currency={payment.currency}
                    visible={payment.amounts_visible}
                  />
                </td>

                <td className="px-3 py-2 text-right">
                  {payment.capabilities?.can_verify ? (
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={verify.isPending}
                      onClick={() => {
                        if (window.confirm(t("verifyConfirm"))) {
                          verify.mutate(payment.id);
                        }
                      }}
                    >
                      {t("verify")}
                    </Button>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
