"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { DateText } from "@/components/i18n/date-text";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { AmountField } from "@/features/billing/amount-field";
import { QuotationStatusBadge } from "@/features/billing/billing-badges";
import { billingQueryKeys, getQuotation, updateQuotation } from "@/services/billing";
import type { Quotation } from "@/types/billing";

type EditableLine = { key: string; id?: string; description: string; amount: string };

function QuotationEditForm({ quotation, onSaved }: { quotation: Quotation; onSaved: () => void }) {
  const t = useTranslations("billing");
  const actions = useTranslations("actions");
  const queryClient = useQueryClient();
  const [title, setTitle] = useState(quotation.title);
  const [description, setDescription] = useState(quotation.description ?? "");
  const [currency, setCurrency] = useState(quotation.currency);
  const [validUntil, setValidUntil] = useState(quotation.valid_until ?? "");
  const [notes, setNotes] = useState(quotation.notes ?? "");
  const [lines, setLines] = useState<EditableLine[]>(
    (quotation.items ?? []).map((line) => ({
      key: line.id,
      id: line.id,
      description: line.description,
      amount: line.line_amount ?? "",
    })),
  );
  const [nextKey, setNextKey] = useState(0);

  const mutation = useMutation({
    mutationFn: () =>
      updateQuotation(quotation.id, {
        title: title.trim(),
        description: description.trim() || null,
        currency,
        valid_until: validUntil || null,
        notes: notes.trim() || null,
        items: lines.map((line) => ({
          ...(line.id ? { id: line.id } : {}),
          description: line.description.trim(),
          amount: line.amount,
        })),
      }),
    onSuccess: async (updated) => {
      queryClient.setQueryData(billingQueryKeys.quotation(quotation.id), updated);
      await queryClient.invalidateQueries({ queryKey: billingQueryKeys.quotations({}) });
      onSaved();
    },
  });

  return (
    <form
      className="flex min-w-0 flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault();
        mutation.mutate();
      }}
    >
      {mutation.isError ? (
        <p role="alert" className="text-destructive text-sm">
          {t("updateFailed")}
        </p>
      ) : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2">
          <Label htmlFor="edit-quotation-title">{t("title")}</Label>
          <Input
            id="edit-quotation-title"
            required
            maxLength={255}
            value={title}
            onChange={(event) => setTitle(event.target.value)}
          />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="edit-quotation-description">{t("description")}</Label>
          <Input
            id="edit-quotation-description"
            maxLength={5000}
            value={description}
            onChange={(event) => setDescription(event.target.value)}
          />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="edit-quotation-currency">{t("currency")}</Label>
          <Select
            id="edit-quotation-currency"
            value={currency}
            onChange={(event) => setCurrency(event.target.value)}
          >
            {["IDR", "USD", "SGD", "EUR"].map((code) => (
              <option key={code} value={code}>
                {code}
              </option>
            ))}
          </Select>
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="edit-quotation-valid-until">{t("validUntil")}</Label>
          <Input
            id="edit-quotation-valid-until"
            type="date"
            value={validUntil}
            onChange={(event) => setValidUntil(event.target.value)}
          />
        </div>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-medium">{t("quotationItems")}</h3>
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={lines.length >= 100}
          onClick={() => {
            setLines((current) => [
              ...current,
              { key: `new-${nextKey}`, description: "", amount: "" },
            ]);
            setNextKey((current) => current + 1);
          }}
        >
          <Plus aria-hidden="true" />
          {t("addLine")}
        </Button>
      </div>
      {lines.map((line, index) => (
        <fieldset
          key={line.key}
          className="border-border grid min-w-0 gap-3 rounded-lg border p-3 sm:grid-cols-[minmax(0,2fr)_minmax(8rem,1fr)_auto]"
        >
          <legend className="sr-only">{t("quotationItemNumber", { number: index + 1 })}</legend>
          <div className="flex flex-col gap-2">
            <Label htmlFor={`edit-quotation-description-${index}`}>{t("description")}</Label>
            <Input
              id={`edit-quotation-description-${index}`}
              required
              maxLength={255}
              value={line.description}
              onChange={(event) =>
                setLines((current) =>
                  current.map((item) =>
                    item.key === line.key ? { ...item, description: event.target.value } : item,
                  ),
                )
              }
            />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor={`edit-quotation-amount-${index}`}>{t("costNominal")}</Label>
            <Input
              id={`edit-quotation-amount-${index}`}
              type="number"
              required
              min="0"
              max="9999999999999"
              step="0.01"
              value={line.amount}
              onChange={(event) =>
                setLines((current) =>
                  current.map((item) =>
                    item.key === line.key ? { ...item, amount: event.target.value } : item,
                  ),
                )
              }
            />
          </div>
          <div className="flex items-end">
            <Button
              type="button"
              variant="outline"
              size="icon"
              aria-label={t("removeLine", { number: index + 1 })}
              disabled={lines.length === 1}
              onClick={() => setLines((current) => current.filter((item) => item.key !== line.key))}
            >
              <Trash2 aria-hidden="true" />
            </Button>
          </div>
        </fieldset>
      ))}
      <div className="flex flex-col gap-2">
        <Label htmlFor="edit-quotation-notes">{t("notes")}</Label>
        <textarea
          id="edit-quotation-notes"
          maxLength={5000}
          value={notes}
          onChange={(event) => setNotes(event.target.value)}
          className="border-input focus-visible:border-ring focus-visible:ring-ring/50 min-h-20 rounded-lg border bg-transparent px-2.5 py-2 text-sm outline-none focus-visible:ring-3"
        />
      </div>
      <div className="flex flex-wrap gap-2">
        <Button
          type="submit"
          disabled={
            mutation.isPending ||
            title.trim() === "" ||
            lines.length === 0 ||
            lines.some((line) => line.description.trim() === "" || line.amount === "")
          }
        >
          {mutation.isPending ? actions("saving") : actions("save")}
        </Button>
        <Button type="button" variant="outline" disabled={mutation.isPending} onClick={onSaved}>
          {actions("cancel")}
        </Button>
      </div>
    </form>
  );
}

export function QuotationDetail({ id, onClose }: { id: string; onClose: () => void }) {
  const t = useTranslations("billing");
  const actions = useTranslations("actions");
  const common = useTranslations("common");
  const [editing, setEditing] = useState(false);
  const query = useQuery({
    queryKey: billingQueryKeys.quotation(id),
    queryFn: () => getQuotation(id),
  });

  if (query.isPending)
    return (
      <div className="text-muted-foreground text-sm" aria-busy="true">
        {t("loading")}
      </div>
    );
  if (query.isError)
    return (
      <BaseErrorState
        title={t("listErrorTitle")}
        description={t("detailUnavailable")}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {actions("retry")}
          </Button>
        }
      />
    );

  const quotation = query.data;
  return (
    <section
      className="border-border bg-card flex min-w-0 flex-col gap-4 rounded-lg border p-4"
      aria-label={t("quotationDetail")}
    >
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">{quotation.quotation_number}</h2>
          <p className="text-muted-foreground text-sm">
            {quotation.client_party?.display_name ?? "—"}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <QuotationStatusBadge status={quotation.status} />
          {!editing && quotation.capabilities?.can_update && quotation.amounts_visible ? (
            <Button type="button" variant="outline" onClick={() => setEditing(true)}>
              {actions("edit")}
            </Button>
          ) : null}
          <Button type="button" variant="outline" onClick={onClose}>
            {common("close")}
          </Button>
        </div>
      </div>
      {editing ? (
        <QuotationEditForm quotation={quotation} onSaved={() => setEditing(false)} />
      ) : (
        <>
          <p className="font-medium">{quotation.title}</p>
          {quotation.description ? (
            <p className="text-muted-foreground text-sm">{quotation.description}</p>
          ) : null}
          <p className="text-muted-foreground text-sm">
            {t("validUntil")}: <DateText value={quotation.valid_until} />
          </p>
          <div className="border-border divide-border divide-y rounded-lg border">
            {(quotation.items ?? []).map((line) => (
              <div
                key={line.id}
                className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
              >
                <span>{line.description}</span>
                <AmountField
                  amount={line.line_amount}
                  currency={quotation.currency}
                  visible={line.amounts_visible}
                />
              </div>
            ))}
          </div>
          <div className="flex justify-end gap-2 font-semibold">
            <span>{t("total")}</span>
            <AmountField
              amount={quotation.total_amount}
              currency={quotation.currency}
              visible={quotation.amounts_visible}
            />
          </div>
          {quotation.notes ? (
            <p className="text-muted-foreground text-sm">{quotation.notes}</p>
          ) : null}
        </>
      )}
    </section>
  );
}
