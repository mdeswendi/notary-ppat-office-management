"use client";

import { useEffect, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";

import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { billingQueryKeys, createQuotation } from "@/services/billing";
import { getPartyDirectory, partyDirectoryKeys } from "@/services/parties";
import type { PartyDirectoryEntry, PartyDirectoryQuery } from "@/types/party";

type QuotationLineDraft = {
  id: string;
  description: string;
  amount: string;
};

const emptyClientOptions: PartyDirectoryEntry[] = [];

const emptyLine = (id: string): QuotationLineDraft => ({
  id,
  description: "",
  amount: "",
});

/** Create a draft quotation and all its priced lines in one atomic API request. */
export function QuotationCreateForm() {
  const t = useTranslations("billing");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [title, setTitle] = useState("");
  const [clientSearchInput, setClientSearchInput] = useState("");
  const [clientSearch, setClientSearch] = useState("");
  const [selectedClient, setSelectedClient] = useState<PartyDirectoryEntry | null>(null);
  const [description, setDescription] = useState("");
  const [currency, setCurrency] = useState("IDR");
  const [validUntil, setValidUntil] = useState("");
  const [notes, setNotes] = useState("");
  const nextLineId = useRef(1);
  const [lines, setLines] = useState<QuotationLineDraft[]>([emptyLine("line-0")]);

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
  const clientOptions = parties.data?.data ?? emptyClientOptions;
  const trimmedClientSearch = clientSearchInput.trim();
  const exactMatches =
    trimmedClientSearch.length >= 2 &&
    clientSearch === trimmedClientSearch &&
    !parties.isPending &&
    !parties.isFetching &&
    !parties.isError
      ? clientOptions.filter(
          (party) =>
            party.display_name?.toLocaleLowerCase() === trimmedClientSearch.toLocaleLowerCase(),
        )
      : [];
  const activeClient = selectedClient ?? (exactMatches.length === 1 ? exactMatches[0] : null);

  const mutation = useMutation({
    mutationFn: () =>
      createQuotation({
        title: title.trim(),
        client_party_id: activeClient?.id ?? "",
        description: description.trim() || null,
        currency,
        valid_until: validUntil || null,
        notes: notes.trim() || null,
        items: lines.map((line) => ({
          description: line.description.trim(),
          amount: line.amount,
        })),
      }),
    onSuccess: async () => {
      setOpen(false);
      setTitle("");
      setClientSearchInput("");
      setClientSearch("");
      setSelectedClient(null);
      setDescription("");
      setCurrency("IDR");
      setValidUntil("");
      setNotes("");
      nextLineId.current = 1;
      setLines([emptyLine("line-0")]);
      await queryClient.invalidateQueries({ queryKey: billingQueryKeys.quotations({}) });
    },
  });

  const updateLine = (index: number, field: keyof QuotationLineDraft, value: string) => {
    setLines((current) =>
      current.map((line, lineIndex) => (lineIndex === index ? { ...line, [field]: value } : line)),
    );
  };

  return (
    <div className="flex flex-col items-start gap-4">
      <PermissionGuard permission="quotations.create">
        <Button type="button" className="gap-2" onClick={() => setOpen((current) => !current)}>
          <Plus aria-hidden="true" />
          {open ? tActions("cancel") : t("newQuotation")}
        </Button>
      </PermissionGuard>

      {open ? (
        <PermissionGuard permission="quotations.create">
          <form
            className="border-border bg-card flex w-full flex-col gap-4 rounded-lg border p-4"
            onSubmit={(event) => {
              event.preventDefault();
              if (!activeClient) return;
              mutation.mutate();
            }}
          >
            <h2 className="text-lg font-semibold">{t("newQuotation")}</h2>
            <p className="text-muted-foreground text-sm">{t("draftOnlyHint")}</p>

            {mutation.isError ? (
              <p role="alert" className="text-destructive text-sm">
                {t("createFailed")}
              </p>
            ) : null}

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-2">
                <Label htmlFor="quotation-title">{t("title")}</Label>
                <Input
                  id="quotation-title"
                  required
                  maxLength={255}
                  value={title}
                  onChange={(event) => setTitle(event.target.value)}
                />
              </div>

              <div className="flex flex-col gap-2">
                <Label htmlFor="quotation-client-search">{t("findClient")}</Label>
                <Input
                  id="quotation-client-search"
                  type="search"
                  value={clientSearchInput}
                  autoComplete="off"
                  role="combobox"
                  aria-autocomplete="list"
                  aria-expanded={trimmedClientSearch.length >= 2 && !activeClient}
                  aria-controls="quotation-client-options"
                  onChange={(event) => {
                    setClientSearchInput(event.target.value);
                    setSelectedClient(null);
                  }}
                  placeholder={t("findClientPlaceholder")}
                />
                <Label htmlFor="quotation-client">{t("client")}</Label>
                <div
                  id="quotation-client"
                  role="group"
                  aria-label={t("client")}
                  className="border-input bg-background min-h-10 rounded-lg border px-3 py-2"
                >
                  {activeClient ? (
                    <div className="flex min-w-0 items-center justify-between gap-2">
                      <span className="truncate">
                        {activeClient.display_name ?? activeClient.id}
                      </span>
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                          setSelectedClient(null);
                          setClientSearchInput("");
                          setClientSearch("");
                        }}
                      >
                        {t("changeClient")}
                      </Button>
                    </div>
                  ) : trimmedClientSearch.length < 2 ? (
                    <span className="text-muted-foreground">{t("clientSearchHint")}</span>
                  ) : clientSearch !== trimmedClientSearch || parties.isPending ? (
                    <span className="text-muted-foreground" aria-live="polite">
                      {t("loadingClients")}
                    </span>
                  ) : parties.isError ? (
                    <span className="text-destructive" role="alert">
                      {t("clientsUnavailable")}
                    </span>
                  ) : clientOptions.length === 0 ? (
                    <span className="text-muted-foreground" role="status">
                      {t("noMatchingClients")}
                    </span>
                  ) : (
                    <ul id="quotation-client-options" role="listbox" className="-mx-2 -my-1">
                      {clientOptions.map((party) => (
                        <li key={party.id} role="option" aria-selected="false">
                          <button
                            type="button"
                            className="hover:bg-accent focus-visible:bg-accent w-full rounded-md px-2 py-2 text-left outline-none"
                            onClick={() => {
                              setSelectedClient(party);
                              setClientSearchInput(party.display_name ?? "");
                            }}
                          >
                            {party.display_name ?? party.id}
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              </div>

              <div className="flex flex-col gap-2">
                <Label htmlFor="quotation-description">{t("description")}</Label>
                <Input
                  id="quotation-description"
                  maxLength={5000}
                  value={description}
                  onChange={(event) => setDescription(event.target.value)}
                />
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                <div className="flex flex-col gap-2">
                  <Label htmlFor="quotation-currency">{t("currency")}</Label>
                  <Select
                    id="quotation-currency"
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
                  <Label htmlFor="quotation-valid-until">{t("validUntil")}</Label>
                  <Input
                    id="quotation-valid-until"
                    type="date"
                    value={validUntil}
                    onChange={(event) => setValidUntil(event.target.value)}
                  />
                </div>
              </div>
            </div>

            <div className="flex flex-col gap-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-medium">{t("quotationItems")}</h3>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="gap-2"
                  disabled={lines.length >= 100}
                  onClick={() =>
                    setLines((current) => [...current, emptyLine(`line-${nextLineId.current++}`)])
                  }
                >
                  <Plus aria-hidden="true" />
                  {t("addLine")}
                </Button>
              </div>

              {lines.map((line, index) => (
                <fieldset
                  key={line.id}
                  className="border-border grid min-w-0 gap-3 rounded-lg border p-3 sm:grid-cols-[minmax(0,2fr)_minmax(8rem,1fr)_auto]"
                >
                  <legend className="sr-only">
                    {t("quotationItemNumber", { number: index + 1 })}
                  </legend>
                  <div className="flex flex-col gap-2">
                    <Label htmlFor={`quotation-line-description-${index}`}>
                      {t("description")}
                    </Label>
                    <Input
                      id={`quotation-line-description-${index}`}
                      required
                      maxLength={255}
                      value={line.description}
                      onChange={(event) => updateLine(index, "description", event.target.value)}
                    />
                  </div>
                  <div className="flex flex-col gap-2">
                    <Label htmlFor={`quotation-line-amount-${index}`}>{t("costNominal")}</Label>
                    <Input
                      id={`quotation-line-amount-${index}`}
                      type="number"
                      required
                      min="0"
                      max="9999999999999"
                      step="0.01"
                      value={line.amount}
                      onChange={(event) => updateLine(index, "amount", event.target.value)}
                    />
                  </div>
                  <div className="flex items-end">
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      aria-label={t("removeLine", { number: index + 1 })}
                      disabled={lines.length === 1}
                      onClick={() =>
                        setLines((current) => current.filter((_, lineIndex) => lineIndex !== index))
                      }
                    >
                      <Trash2 aria-hidden="true" />
                    </Button>
                  </div>
                </fieldset>
              ))}
            </div>

            <div className="flex flex-col gap-2">
              <Label htmlFor="quotation-notes">{t("notes")}</Label>
              <textarea
                id="quotation-notes"
                maxLength={5000}
                value={notes}
                onChange={(event) => setNotes(event.target.value)}
                className="border-input focus-visible:border-ring focus-visible:ring-ring/50 min-h-20 rounded-lg border bg-transparent px-2.5 py-2 text-sm outline-none focus-visible:ring-3"
              />
            </div>

            {parties.isError ? (
              <p className="text-destructive text-sm">{t("clientsUnavailable")}</p>
            ) : null}

            <div className="flex flex-wrap gap-2">
              <Button
                type="submit"
                disabled={
                  mutation.isPending ||
                  title.trim() === "" ||
                  activeClient === null ||
                  lines.some((line) => line.description.trim() === "" || line.amount === "")
                }
              >
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
        </PermissionGuard>
      ) : null}
    </div>
  );
}
