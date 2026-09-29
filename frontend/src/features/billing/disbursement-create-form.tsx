"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useTranslations } from "next-intl";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { billingQueryKeys, createDisbursement } from "@/services/billing";
import { getPartyDirectory, partyDirectoryKeys } from "@/services/parties";
import type { PartyDirectoryQuery } from "@/types/party";

const todayLocal = () => {
  const now = new Date();
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 10);
};

/** A paid process cost, never a quotation or payment received from a client. */
export function DisbursementCreateForm() {
  const t = useTranslations("billing");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [clientSearchInput, setClientSearchInput] = useState("");
  const [clientSearch, setClientSearch] = useState("");

  useEffect(() => {
    const timer = setTimeout(() => setClientSearch(clientSearchInput.trim()), 300);
    return () => clearTimeout(timer);
  }, [clientSearchInput]);

  const partyQuery: PartyDirectoryQuery = { page: 1, per_page: 20, search: clientSearch };
  const parties = useQuery({
    queryKey: partyDirectoryKeys.list(partyQuery),
    queryFn: () => getPartyDirectory(partyQuery),
    enabled: open,
  });

  const schema = z.object({
    client_party_id: z.string().min(1, t("costValidation.clientRequired")),
    description: z.string().trim().min(1, t("costValidation.descriptionRequired")).max(255),
    amount: z.string().refine((value) => {
      const number = Number(value);
      return (
        value.trim() !== "" && Number.isFinite(number) && number > 0 && number <= 9_999_999_999_999
      );
    }, t("costValidation.amountPositive")),
    incurred_on: z
      .string()
      .min(1, t("costValidation.dateRequired"))
      .refine((value) => value !== "" && value <= todayLocal(), t("costValidation.dateFuture")),
    reference: z.string().trim().max(255),
    notes: z.string().trim().max(5000),
  });
  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      client_party_id: "",
      description: "",
      amount: "",
      incurred_on: "",
      reference: "",
      notes: "",
    },
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) =>
      createDisbursement({
        client_party_id: values.client_party_id,
        description: values.description.trim(),
        amount: values.amount,
        incurred_on: values.incurred_on,
        reference: values.reference.trim() || null,
        notes: values.notes.trim() || null,
        currency: "IDR",
      }),
    onSuccess: async () => {
      form.reset();
      setClientSearchInput("");
      setClientSearch("");
      setOpen(false);
      await queryClient.invalidateQueries({ queryKey: billingQueryKeys.disbursements({}) });
    },
  });

  return (
    <div className="flex flex-col items-start gap-4">
      <PermissionGuard
        permission="disbursements.create"
        fallback={
          <p className="text-muted-foreground text-sm">{t("costCreatePermissionRequired")}</p>
        }
      >
        <Button type="button" className="gap-2" onClick={() => setOpen((value) => !value)}>
          <Plus aria-hidden="true" />
          {open ? tActions("cancel") : t("newProcessCost")}
        </Button>
        {open ? (
          <form
            className="border-border bg-card flex w-full max-w-2xl flex-col gap-4 rounded-lg border p-4"
            onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
            noValidate
          >
            <h2 className="text-lg font-semibold">{t("newProcessCost")}</h2>
            <p className="text-muted-foreground text-sm">{t("paidCostsOnlyHint")}</p>
            {mutation.isError ? (
              <p role="alert" className="text-destructive text-sm">
                {t("costCreateFailed")}
              </p>
            ) : null}

            <div className="flex flex-col gap-2">
              <Label htmlFor="cost-client-search">{t("findClient")}</Label>
              <Input
                id="cost-client-search"
                type="search"
                value={clientSearchInput}
                onChange={(event) => setClientSearchInput(event.target.value)}
                placeholder={t("findClientPlaceholder")}
              />
              <Label htmlFor="cost-client">{t("client")}</Label>
              <Select
                id="cost-client"
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

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-2 sm:col-span-2">
                <Label htmlFor="cost-description">{t("processCostDescription")}</Label>
                <Input
                  id="cost-description"
                  maxLength={255}
                  placeholder={t("processCostExample")}
                  aria-invalid={!!form.formState.errors.description}
                  {...form.register("description")}
                />
                {form.formState.errors.description ? (
                  <p role="alert" className="text-destructive text-sm">
                    {form.formState.errors.description.message}
                  </p>
                ) : null}
              </div>
              <div className="flex flex-col gap-2">
                <Label htmlFor="cost-amount">{t("amount")}</Label>
                <Input
                  id="cost-amount"
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
                <Label htmlFor="cost-date">{t("processCostPaidOn")}</Label>
                <Input
                  id="cost-date"
                  type="date"
                  max={todayLocal()}
                  aria-invalid={!!form.formState.errors.incurred_on}
                  {...form.register("incurred_on")}
                />
                {form.formState.errors.incurred_on ? (
                  <p role="alert" className="text-destructive text-sm">
                    {form.formState.errors.incurred_on.message}
                  </p>
                ) : null}
              </div>
              <div className="flex flex-col gap-2 sm:col-span-2">
                <Label htmlFor="cost-reference">{t("processCostReference")}</Label>
                <Input id="cost-reference" maxLength={255} {...form.register("reference")} />
              </div>
              <div className="flex flex-col gap-2 sm:col-span-2">
                <Label htmlFor="cost-notes">{t("notes")}</Label>
                <Textarea id="cost-notes" maxLength={5000} {...form.register("notes")} />
              </div>
            </div>

            <div className="flex flex-wrap gap-2">
              <Button type="submit" disabled={mutation.isPending || parties.isError}>
                {mutation.isPending ? tActions("saving") : tActions("save")}
              </Button>
              <Button
                type="button"
                variant="outline"
                disabled={mutation.isPending}
                onClick={() => setOpen(false)}
              >
                {tActions("cancel")}
              </Button>
            </div>
          </form>
        ) : null}
      </PermissionGuard>
    </div>
  );
}
