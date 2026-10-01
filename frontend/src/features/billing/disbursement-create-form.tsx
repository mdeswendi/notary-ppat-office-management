"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useTranslations } from "next-intl";
import { useForm, useWatch } from "react-hook-form";
import { z } from "zod";

import { PermissionGuard } from "@/components/permission-guard";
import { useCurrentUser } from "@/features/auth/use-current-user";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { can } from "@/lib/permissions/can";
import { billingQueryKeys, createDisbursement } from "@/services/billing";
import { getMatters, matterQueryKeys } from "@/services/matters";
import { getPartyDirectory, partyDirectoryKeys } from "@/services/parties";
import { getProjects, projectQueryKeys } from "@/services/projects";
import type { PartyDirectoryEntry, PartyDirectoryQuery } from "@/types/party";

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
  const { data: user } = useCurrentUser();
  const [open, setOpen] = useState(false);
  const [clientSearchInput, setClientSearchInput] = useState("");
  const [clientSearch, setClientSearch] = useState("");
  const [selectedClient, setSelectedClient] = useState<PartyDirectoryEntry | null>(null);
  const [projectSearchInput, setProjectSearchInput] = useState("");
  const [projectSearch, setProjectSearch] = useState("");
  const [matterSearchInput, setMatterSearchInput] = useState("");
  const [matterSearch, setMatterSearch] = useState("");

  useEffect(() => {
    const timer = setTimeout(() => setClientSearch(clientSearchInput.trim()), 300);
    return () => clearTimeout(timer);
  }, [clientSearchInput]);

  useEffect(() => {
    const timer = setTimeout(() => setProjectSearch(projectSearchInput.trim()), 300);
    return () => clearTimeout(timer);
  }, [projectSearchInput]);

  useEffect(() => {
    const timer = setTimeout(() => setMatterSearch(matterSearchInput.trim()), 300);
    return () => clearTimeout(timer);
  }, [matterSearchInput]);

  const partyQuery: PartyDirectoryQuery = { page: 1, per_page: 20, search: clientSearch };
  const parties = useQuery({
    queryKey: partyDirectoryKeys.list(partyQuery),
    queryFn: () => getPartyDirectory(partyQuery),
    enabled: open && clientSearch.length >= 2,
  });
  const clientOptions = parties.data?.data ?? [];
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
  const activeClientId = activeClient?.id ?? "";

  const projectQuery = {
    page: 1,
    per_page: 20,
    search: projectSearch,
    status: "" as const,
    priority: "" as const,
  };
  const projects = useQuery({
    queryKey: projectQueryKeys.list(projectQuery),
    queryFn: () => getProjects(projectQuery),
    enabled: open && can(user, "projects.view"),
  });

  const schema = z.object({
    client_party_id: z.string().min(1, t("costValidation.clientRequired")),
    project_id: z.string().min(1, t("costValidation.projectRequired")),
    matter_id: z.string(),
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
      project_id: "",
      matter_id: "",
      description: "",
      amount: "",
      incurred_on: "",
      reference: "",
      notes: "",
    },
  });
  const { setValue } = form;

  useEffect(() => {
    setValue("client_party_id", activeClientId, { shouldValidate: activeClientId !== "" });
  }, [activeClientId, setValue]);

  const projectId = useWatch({ control: form.control, name: "project_id" });
  const matterQuery = {
    page: 1,
    per_page: 20,
    search: matterSearch,
    status: "" as const,
    priority: "" as const,
    project_id: projectId,
  };
  const notaryMatters = useQuery({
    queryKey: matterQueryKeys.list("NOTARY", matterQuery),
    queryFn: () => getMatters("NOTARY", matterQuery),
    enabled: open && !!projectId && can(user, "notary.matters.view"),
  });
  const ppatMatters = useQuery({
    queryKey: matterQueryKeys.list("PPAT", matterQuery),
    queryFn: () => getMatters("PPAT", matterQuery),
    enabled: open && !!projectId && can(user, "ppat.matters.view"),
  });
  const matterOptions = [...(notaryMatters.data?.data ?? []), ...(ppatMatters.data?.data ?? [])];

  const mutation = useMutation({
    mutationFn: (values: FormValues) =>
      createDisbursement({
        client_party_id: values.client_party_id,
        project_id: values.project_id,
        matter_id: values.matter_id || null,
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
      setSelectedClient(null);
      setProjectSearchInput("");
      setProjectSearch("");
      setMatterSearchInput("");
      setMatterSearch("");
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
                autoComplete="off"
                role="combobox"
                aria-autocomplete="list"
                aria-expanded={trimmedClientSearch.length >= 2 && !activeClient}
                aria-controls="cost-client-options"
                aria-invalid={!!form.formState.errors.client_party_id}
                onChange={(event) => {
                  setClientSearchInput(event.target.value);
                  setSelectedClient(null);
                  form.setValue("client_party_id", "");
                }}
                placeholder={t("findClientPlaceholder")}
              />
              {activeClient ? (
                <div className="border-input bg-background flex min-h-10 items-center justify-between gap-2 rounded-lg border px-3 py-1.5">
                  <span className="min-w-0 truncate text-sm" role="status">
                    {t("selectedClient", { name: activeClient.display_name ?? activeClient.id })}
                  </span>
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                      setSelectedClient(null);
                      setClientSearchInput("");
                      setClientSearch("");
                      form.setValue("client_party_id", "");
                    }}
                  >
                    {t("changeClient")}
                  </Button>
                </div>
              ) : trimmedClientSearch.length < 2 ? (
                <p className="text-muted-foreground text-sm">{t("clientSearchHint")}</p>
              ) : clientSearch !== trimmedClientSearch ||
                parties.isPending ||
                parties.isFetching ? (
                <p className="text-muted-foreground text-sm" aria-live="polite">
                  {t("loadingClients")}
                </p>
              ) : parties.isError ? (
                <p role="alert" className="text-destructive text-sm">
                  {t("clientsUnavailable")}
                </p>
              ) : clientOptions.length === 0 ? (
                <p role="status" className="text-muted-foreground text-sm">
                  {t("noMatchingClients")}
                </p>
              ) : (
                <ul
                  id="cost-client-options"
                  role="listbox"
                  className="border-input rounded-lg border p-1"
                >
                  {clientOptions.map((party) => (
                    <li key={party.id}>
                      <button
                        type="button"
                        role="option"
                        aria-selected="false"
                        className="hover:bg-accent focus-visible:bg-accent w-full rounded-md px-2 py-2 text-left text-sm outline-none"
                        onClick={() => {
                          setSelectedClient(party);
                          setClientSearchInput(party.display_name ?? "");
                          form.setValue("client_party_id", party.id, { shouldValidate: true });
                        }}
                      >
                        <span className="block">{party.display_name ?? party.id}</span>
                        {party.primary_phone || party.primary_email ? (
                          <span className="text-muted-foreground block text-xs">
                            {party.primary_phone ?? party.primary_email}
                          </span>
                        ) : null}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              {form.formState.errors.client_party_id ? (
                <p role="alert" className="text-destructive text-sm">
                  {form.formState.errors.client_party_id.message}
                </p>
              ) : null}
            </div>

            <div className="flex flex-col gap-2">
              <Label htmlFor="cost-project-search">{t("findProject")}</Label>
              <Input
                id="cost-project-search"
                type="search"
                value={projectSearchInput}
                onChange={(event) => {
                  setProjectSearchInput(event.target.value);
                  form.setValue("project_id", "");
                  form.setValue("matter_id", "");
                  setMatterSearchInput("");
                  setMatterSearch("");
                }}
                placeholder={t("findProjectPlaceholder")}
                disabled={!can(user, "projects.view")}
              />
              <Label htmlFor="cost-project">{t("project")}</Label>
              <Select
                id="cost-project"
                aria-invalid={!!form.formState.errors.project_id}
                disabled={!can(user, "projects.view")}
                {...form.register("project_id", {
                  onChange: () => {
                    form.setValue("matter_id", "");
                    setMatterSearchInput("");
                    setMatterSearch("");
                  },
                })}
              >
                <option value="">
                  {projects.isPending ? t("loadingProjects") : t("selectProject")}
                </option>
                {(projects.data?.data ?? []).map((project) => (
                  <option key={project.id} value={project.id}>
                    {project.project_number} · {project.title}
                  </option>
                ))}
              </Select>
              {form.formState.errors.project_id ? (
                <p role="alert" className="text-destructive text-sm">
                  {form.formState.errors.project_id.message}
                </p>
              ) : null}
              {!can(user, "projects.view") || projects.isError ? (
                <p role="alert" className="text-destructive text-sm">
                  {t("projectsUnavailable")}
                </p>
              ) : null}
              <Label htmlFor="cost-matter">{t("matterOptional")}</Label>
              <Input
                id="cost-matter-search"
                type="search"
                aria-label={t("findMatter")}
                value={matterSearchInput}
                onChange={(event) => {
                  setMatterSearchInput(event.target.value);
                  form.setValue("matter_id", "");
                }}
                placeholder={t("findMatterPlaceholder")}
                disabled={
                  !projectId ||
                  (!can(user, "notary.matters.view") && !can(user, "ppat.matters.view"))
                }
              />
              <Select
                id="cost-matter"
                disabled={
                  !projectId ||
                  (!can(user, "notary.matters.view") && !can(user, "ppat.matters.view"))
                }
                {...form.register("matter_id")}
              >
                <option value="">{t("selectMatterOptional")}</option>
                {matterOptions.map((matter) => (
                  <option key={matter.id} value={matter.id}>
                    {matter.matter_number} · {matter.title}
                  </option>
                ))}
              </Select>
              {notaryMatters.isError || ppatMatters.isError ? (
                <p role="alert" className="text-destructive text-sm">
                  {t("mattersUnavailable")}
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
              <Button
                type="submit"
                disabled={
                  mutation.isPending ||
                  parties.isError ||
                  projects.isError ||
                  !can(user, "projects.view")
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
        ) : null}
      </PermissionGuard>
    </div>
  );
}
