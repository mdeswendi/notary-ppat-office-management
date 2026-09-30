"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { billingQueryKeys, createClientReceipt, updateClientReceipt } from "@/services/billing";
import { getPartyDirectory, partyDirectoryKeys } from "@/services/parties";
import { reportQueryKeys } from "@/services/reports";
import type { ClientReceipt, PaymentMethod } from "@/types/billing";
import type { PartyDirectoryQuery } from "@/types/party";

const todayLocal = () => {
  const now = new Date();
  return new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 10);
};

const paymentMethods: PaymentMethod[] = ["CASH", "BANK_TRANSFER", "CARD", "OTHER"];

export function ClientReceiptForm({
  receipt,
  onDone,
}: {
  receipt?: ClientReceipt;
  onDone: () => void;
}) {
  const t = useTranslations("billing");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();
  const editing = receipt !== undefined;
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");

  useEffect(() => {
    const timer = setTimeout(() => setSearch(searchInput.trim()), 300);
    return () => clearTimeout(timer);
  }, [searchInput]);

  const partyQuery: PartyDirectoryQuery = { page: 1, per_page: 20, search };
  const parties = useQuery({
    queryKey: partyDirectoryKeys.list(partyQuery),
    queryFn: () => getPartyDirectory(partyQuery),
    enabled: !editing,
  });

  const schema = z.object({
    client_party_id: editing ? z.string() : z.string().min(1, t("receiptClientRequired")),
    amount: z.string().refine((value) => {
      const number = Number(value);
      return (
        value.trim() !== "" &&
        /^\d+(?:\.\d{1,2})?$/.test(value.trim()) &&
        Number.isFinite(number) &&
        number > 0 &&
        number <= 9_999_999_999_999
      );
    }, t("receiptAmountPositive")),
    received_on: z
      .string()
      .min(1, t("receiptDateRequired"))
      .refine((value) => value !== "" && value <= todayLocal(), t("receiptDateFuture")),
    method_code: z.enum(paymentMethods, { message: t("receiptMethodRequired") }),
  });
  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      client_party_id: receipt?.client_party?.id ?? "",
      amount: receipt?.amount ?? "",
      received_on: receipt?.received_on ?? todayLocal(),
      method_code: receipt?.method_code ?? "CASH",
    },
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) =>
      receipt
        ? updateClientReceipt(receipt.id, {
            amount: values.amount,
            received_on: values.received_on,
            method_code: values.method_code,
          })
        : createClientReceipt({
            client_party_id: values.client_party_id,
            amount: values.amount,
            received_on: values.received_on,
            method_code: values.method_code,
          }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: billingQueryKeys.clientReceipts({ per_page: 100 }),
      });
      await queryClient.invalidateQueries({ queryKey: reportQueryKeys.all() });
      onDone();
    },
  });

  const prefix = receipt ? `receipt-edit-${receipt.id}` : "receipt-create";

  return (
    <form
      className="border-border bg-card flex w-full flex-col gap-4 rounded-lg border p-4"
      onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
      noValidate
    >
      <h2 className="text-lg font-semibold">
        {editing ? t("editClientReceipt") : t("newClientReceipt")}
      </h2>
      {mutation.isError ? (
        <p role="alert" className="text-destructive text-sm">
          {editing ? t("receiptUpdateFailed") : t("receiptCreateFailed")}
        </p>
      ) : null}

      {!editing ? (
        <div className="grid gap-2 sm:grid-cols-2">
          <div className="flex flex-col gap-2">
            <Label htmlFor={`${prefix}-search`}>{t("findClient")}</Label>
            <Input
              id={`${prefix}-search`}
              type="search"
              value={searchInput}
              onChange={(event) => setSearchInput(event.target.value)}
              placeholder={t("findClientPlaceholder")}
            />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor={`${prefix}-client`}>{t("client")}</Label>
            <Select
              id={`${prefix}-client`}
              aria-invalid={!!form.formState.errors.client_party_id}
              {...form.register("client_party_id")}
            >
              <option value="">
                {parties.isPending ? t("loadingClients") : t("selectClient")}
              </option>
              {(parties.data?.data ?? []).map((party) => (
                <option key={party.id} value={party.id}>
                  {party.display_name ?? party.id}
                </option>
              ))}
            </Select>
            {form.formState.errors.client_party_id ? (
              <p role="alert" className="text-destructive text-sm">
                {form.formState.errors.client_party_id.message}
              </p>
            ) : null}
            {parties.isError ? (
              <p role="alert" className="text-destructive text-sm">
                {t("clientsUnavailable")}
              </p>
            ) : null}
          </div>
        </div>
      ) : (
        <p className="text-muted-foreground text-sm">
          {receipt?.client_party?.display_name ?? "—"}
        </p>
      )}

      <div className="grid gap-4 sm:grid-cols-3">
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${prefix}-amount`}>{t("amount")}</Label>
          <Input
            id={`${prefix}-amount`}
            type="number"
            min="0.01"
            max="9999999999999"
            step="0.01"
            inputMode="decimal"
            aria-invalid={!!form.formState.errors.amount}
            {...form.register("amount")}
          />
          {form.formState.errors.amount ? (
            <p role="alert" className="text-destructive text-sm">
              {form.formState.errors.amount.message}
            </p>
          ) : null}
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${prefix}-date`}>{t("receivedOn")}</Label>
          <Input
            id={`${prefix}-date`}
            type="date"
            max={todayLocal()}
            aria-invalid={!!form.formState.errors.received_on}
            {...form.register("received_on")}
          />
          {form.formState.errors.received_on ? (
            <p role="alert" className="text-destructive text-sm">
              {form.formState.errors.received_on.message}
            </p>
          ) : null}
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${prefix}-method`}>{t("paymentMethod")}</Label>
          <Select
            id={`${prefix}-method`}
            aria-invalid={!!form.formState.errors.method_code}
            {...form.register("method_code")}
          >
            <option value="">{t("selectPaymentMethod")}</option>
            {paymentMethods.map((method) => (
              <option key={method} value={method}>
                {t(`methods.${method}`)}
              </option>
            ))}
          </Select>
          {form.formState.errors.method_code ? (
            <p role="alert" className="text-destructive text-sm">
              {form.formState.errors.method_code.message}
            </p>
          ) : null}
        </div>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={mutation.isPending || (!editing && parties.isError)}>
          {mutation.isPending ? tActions("saving") : tActions("save")}
        </Button>
        <Button type="button" variant="outline" disabled={mutation.isPending} onClick={onDone}>
          {tActions("cancel")}
        </Button>
      </div>
    </form>
  );
}
