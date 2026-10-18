"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Building2, ExternalLink, Search, User, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { useForm, type UseFormRegisterReturn } from "react-hook-form";
import { z } from "zod";

import { InlineAlert } from "@/components/feedback/inline-alert";
import { FormActions } from "@/components/forms/form-actions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { toProjectErrorKey } from "@/features/projects/project-errors";
import { Link, useRouter } from "@/i18n/navigation";
import { getPartyDirectory, partyDirectoryKeys } from "@/services/parties";
import { addProjectParty } from "@/services/project-parties";
import { createProject, projectQueryKeys, updateProject } from "@/services/projects";
import type { PartyDirectoryEntry } from "@/types/party";
import { PROJECT_PRIORITIES, type Project } from "@/types/project";

/**
 * Create and edit a Project's ordinary attributes.
 *
 * **Four things this form deliberately does not offer**, each because the
 * backend refuses it outright rather than ignoring it:
 *
 *   Office            a Project is created in your own Office. `ALL` is
 *                     cross-office *reach*, never cross-office creation (D-097),
 *                     so there is no selector to render.
 *   Project number    allocated server-side and immutable. Shown read-only on
 *                     the detail page; never an input.
 *   Status            a new Project is OPEN, and changing it later answers to
 *                     `projects.change_status` on its own control (D-091).
 *   Person in charge  answers to `projects.assign`, likewise.
 *
 * A form that showed those fields and silently dropped them would tell the user
 * their choice was accepted. The API returns 422 for each, so this form does not
 * present a choice that does not exist.
 *
 * Zod covers shape and length for usability. The Form Request stays
 * authoritative, and **no date-ordering rule appears on either side**: nothing
 * canonical says a Project may not be back-dated or planned in an unusual order,
 * so neither layer invents one.
 */
export function ProjectForm({
  project,
  initialClientPartyId,
}: {
  project?: Project;
  initialClientPartyId?: string;
}) {
  const t = useTranslations("projects");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const isEdit = project !== undefined;

  const [clientSearchInput, setClientSearchInput] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [clientSelection, setClientSelection] = useState<PartyDirectoryEntry | null | undefined>(
    undefined,
  );

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(clientSearchInput.trim()), 300);

    return () => clearTimeout(timer);
  }, [clientSearchInput]);

  const partyQuery = useQuery({
    queryKey: partyDirectoryKeys.list({ page: 1, per_page: 20, search: debouncedSearch }),
    queryFn: () => getPartyDirectory({ page: 1, per_page: 20, search: debouncedSearch }),
    enabled: !isEdit,
  });

  const initialMatch = initialClientPartyId
    ? (partyQuery.data?.data?.find((p) => p.id === initialClientPartyId) ?? null)
    : null;

  const selectedClient = clientSelection !== undefined ? clientSelection : initialMatch;

  const schema = z.object({
    title: z
      .string()
      .trim()
      .min(1, { message: t("validation.titleRequired") })
      .max(255, { message: t("validation.tooLong") }),
    description: z.string().trim(),
    priority: z.union([z.enum(PROJECT_PRIORITIES), z.literal("")]),
    opened_at: z.string().trim(),
    target_completion_date: z.string().trim(),
  });

  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      title: "",
      description: "",
      priority: "NORMAL",
      opened_at: "",
      target_completion_date: "",
    },
  });

  const { reset } = form;

  useEffect(() => {
    if (project) {
      reset({
        title: project.title,
        description: project.description ?? "",
        priority: project.priority ?? "",
        opened_at: project.opened_at ?? "",
        target_completion_date: project.target_completion_date ?? "",
      });
    }
  }, [project, reset]);

  /** Empty strings become null so an untouched optional field stays absent. */
  const payload = (values: FormValues) => {
    const blank = (value: string) => (value.trim() === "" ? null : value.trim());

    return {
      title: values.title.trim(),
      description: blank(values.description),
      priority: values.priority === "" ? null : values.priority,
      opened_at: blank(values.opened_at),
      target_completion_date: blank(values.target_completion_date),
    };
  };

  const mutation = useMutation({
    mutationFn: async (values: FormValues) => {
      if (project) {
        return updateProject(project.id, payload(values));
      }

      const saved = await createProject(payload(values));

      if (selectedClient) {
        try {
          await addProjectParty(saved.id, {
            party_id: selectedClient.id,
            role_code: "CLIENT",
            is_primary: true,
          });
        } catch (error) {
          console.error("Failed to link primary client to new project", error);
        }
      }

      return saved;
    },
    onSuccess: async (saved) => {
      await queryClient.invalidateQueries({ queryKey: projectQueryKeys.all });

      router.push(`/projects/${saved.id}`);
    },
    onError: (error: unknown) => {
      form.setError("root", { message: t(`errors.${toProjectErrorKey(error)}`) });
    },
  });

  const onSubmit = form.handleSubmit((values) => {
    form.clearErrors("root");
    mutation.mutate(values);
  });

  return (
    <form onSubmit={onSubmit} noValidate className="flex max-w-2xl flex-col gap-6">
      {form.formState.errors.root ? (
        <InlineAlert>{form.formState.errors.root.message}</InlineAlert>
      ) : null}

      {!isEdit ? (
        <p className="text-muted-foreground text-sm">{t("createHint")}</p>
      ) : (
        <div className="flex flex-col gap-1">
          <span className="text-sm font-medium">{t("referenceLabel")}</span>
          <span className="text-muted-foreground font-mono text-sm">{project.project_number}</span>
          <span className="text-muted-foreground text-xs">{t("referenceHint")}</span>
        </div>
      )}

      <Field
        id="title"
        label={t("titleLabel")}
        registration={form.register("title")}
        error={form.formState.errors.title?.message}
      />

      {!isEdit ? (
        <div className="border-border bg-muted/20 flex flex-col gap-2 rounded-lg border p-4">
          <div className="flex flex-col gap-1">
            <Label htmlFor="client-search">{t("primaryClientLabel")}</Label>
            <p className="text-muted-foreground text-xs">{t("primaryClientHint")}</p>
          </div>

          {selectedClient ? (
            <div className="border-primary/30 bg-primary/5 flex items-center justify-between gap-3 rounded-md border p-3">
              <div className="flex min-w-0 items-center gap-3">
                <div className="bg-primary/10 text-primary flex h-9 w-9 shrink-0 items-center justify-center rounded-full">
                  {selectedClient.party_type === "COMPANY" ? (
                    <Building2 className="h-5 w-5" aria-hidden="true" />
                  ) : (
                    <User className="h-5 w-5" aria-hidden="true" />
                  )}
                </div>
                <div className="flex min-w-0 flex-col">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-foreground truncate text-sm font-medium">
                      {selectedClient.display_name}
                    </span>
                    <Badge tone="primary">
                      {t(`partyTypes.${selectedClient.party_type ?? "INDIVIDUAL"}`)}
                    </Badge>
                  </div>
                  {selectedClient.primary_phone || selectedClient.primary_email ? (
                    <span className="text-muted-foreground truncate text-xs">
                      {[selectedClient.primary_phone, selectedClient.primary_email]
                        .filter(Boolean)
                        .join(" • ")}
                    </span>
                  ) : null}
                </div>
              </div>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => setClientSelection(null)}
                className="text-muted-foreground hover:text-foreground shrink-0"
              >
                <X className="mr-1 h-4 w-4" aria-hidden="true" />
                {t("clearClientAction")}
              </Button>
            </div>
          ) : (
            <div className="flex flex-col gap-2">
              <div className="relative">
                <Search
                  className="text-muted-foreground absolute top-2.5 left-3 h-4 w-4"
                  aria-hidden="true"
                />
                <Input
                  id="client-search"
                  value={clientSearchInput}
                  onChange={(e) => setClientSearchInput(e.target.value)}
                  placeholder={t("searchClientPlaceholder")}
                  className="pr-8 pl-9"
                />
                {clientSearchInput ? (
                  <button
                    type="button"
                    onClick={() => setClientSearchInput("")}
                    aria-label={t("clearClientAction")}
                    className="text-muted-foreground hover:text-foreground absolute top-2.5 right-2.5"
                  >
                    <X className="h-4 w-4" aria-hidden="true" />
                  </button>
                ) : null}
              </div>

              {debouncedSearch.length >= 2 ? (
                <div className="border-border bg-popover rounded-md border p-2 shadow-sm">
                  {partyQuery.isPending ? (
                    <p className="text-muted-foreground p-2 text-xs">{t("searchingClients")}</p>
                  ) : (partyQuery.data?.data?.length ?? 0) > 0 ? (
                    <ul className="divide-border/50 max-h-48 divide-y overflow-y-auto">
                      {partyQuery.data?.data.map((party) => (
                        <li key={party.id}>
                          <button
                            type="button"
                            onClick={() => {
                              setClientSelection(party);
                              setClientSearchInput("");
                            }}
                            className="hover:bg-muted flex w-full items-center justify-between gap-2 rounded p-2 text-left"
                          >
                            <div className="flex min-w-0 flex-col">
                              <span className="truncate text-sm font-medium">
                                {party.display_name}
                              </span>
                              {party.primary_phone || party.primary_email ? (
                                <span className="text-muted-foreground truncate text-xs">
                                  {[party.primary_phone, party.primary_email]
                                    .filter(Boolean)
                                    .join(" • ")}
                                </span>
                              ) : null}
                            </div>
                            <Badge tone="muted">
                              {t(`partyTypes.${party.party_type ?? "INDIVIDUAL"}`)}
                            </Badge>
                          </button>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <div className="flex flex-col items-center gap-2 p-3 text-center">
                      <p className="text-muted-foreground text-xs">{t("noClientsFound")}</p>
                      <div className="flex flex-wrap items-center justify-center gap-3 text-xs">
                        <Link
                          href="/parties/individuals/new"
                          target="_blank"
                          rel="noreferrer"
                          className="text-primary inline-flex items-center gap-1 hover:underline"
                        >
                          <span>{t("createNewIndividual")}</span>
                          <ExternalLink className="h-3 w-3" aria-hidden="true" />
                        </Link>
                        <span className="text-muted-foreground">•</span>
                        <Link
                          href="/parties/companies/new"
                          target="_blank"
                          rel="noreferrer"
                          className="text-primary inline-flex items-center gap-1 hover:underline"
                        >
                          <span>{t("createNewCompany")}</span>
                          <ExternalLink className="h-3 w-3" aria-hidden="true" />
                        </Link>
                      </div>
                    </div>
                  )}
                </div>
              ) : (
                <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-2 text-xs">
                  <span>{t("clientSearchMinHint")}</span>
                  <div className="flex items-center gap-2">
                    <span>{t("createNewPartyPrompt")}</span>
                    <Link
                      href="/parties/individuals/new"
                      target="_blank"
                      rel="noreferrer"
                      className="text-primary hover:underline"
                    >
                      {t("createNewIndividual")}
                    </Link>
                    <span>/</span>
                    <Link
                      href="/parties/companies/new"
                      target="_blank"
                      rel="noreferrer"
                      className="text-primary hover:underline"
                    >
                      {t("createNewCompany")}
                    </Link>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
      ) : null}

      <div className="flex flex-col gap-2">
        <Label htmlFor="description">{t("descriptionLabel")}</Label>
        <Textarea id="description" rows={4} {...form.register("description")} />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2">
          <Label htmlFor="priority">{t("priorityLabel")}</Label>
          <Select id="priority" {...form.register("priority")}>
            <option value="">{t("noPriority")}</option>
            {PROJECT_PRIORITIES.map((code) => (
              <option key={code} value={code}>
                {t(`priorities.${code}`)}
              </option>
            ))}
          </Select>
        </div>

        <Field
          id="opened_at"
          label={t("openedAtLabel")}
          registration={form.register("opened_at")}
          error={form.formState.errors.opened_at?.message}
          type="date"
        />
      </div>

      <Field
        id="target_completion_date"
        label={t("targetCompletionLabel")}
        registration={form.register("target_completion_date")}
        error={form.formState.errors.target_completion_date?.message}
        type="date"
      />

      <FormActions>
        <Button type="submit" disabled={mutation.isPending}>
          {mutation.isPending ? tActions("saving") : tActions("save")}
        </Button>
      </FormActions>
    </form>
  );
}

/**
 * One labelled text input.
 *
 * Takes the registration and the message rather than the whole form object,
 * which keeps it fully typed — passing `UseFormReturn` through a generic
 * boundary is where an `any` usually creeps in (AGENTS.md section 53).
 */
function Field({
  id,
  label,
  registration,
  error,
  type = "text",
}: {
  id: string;
  label: string;
  registration: UseFormRegisterReturn;
  error?: string;
  type?: string;
}) {
  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <Input
        id={id}
        type={type}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${id}-error` : undefined}
        {...registration}
      />
      {error ? (
        <p id={`${id}-error`} className="text-destructive text-sm">
          {error}
        </p>
      ) : null}
    </div>
  );
}
