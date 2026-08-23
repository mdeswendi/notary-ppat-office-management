"use client";

import { useEffect } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useForm, type UseFormRegisterReturn } from "react-hook-form";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { matterBasePath } from "@/features/matters/matter-domain";
import { toMatterErrorKey } from "@/features/matters/matter-errors";
import { useRouter } from "@/i18n/navigation";
import {
  createMatter,
  getMatterServiceTypeOptions,
  matterQueryKeys,
  updateMatter,
} from "@/services/matters";
import { getProjects, projectQueryKeys } from "@/services/projects";
import type { Matter, MatterDomain } from "@/types/matter";
import { PROJECT_PRIORITIES } from "@/types/project";

/**
 * Create and edit a Matter's ordinary attributes.
 *
 * **Six things this form deliberately does not offer**, each because the backend
 * refuses it outright rather than ignoring it:
 *
 *   Domain            decided by the address you are on (D-101). There is no
 *                     selector, and posting one is a 422.
 *   Office            inherited from the parent Project (D-099), never chosen.
 *   Matter number     allocated server-side and immutable (D-103). Shown
 *                     read-only when editing; never an input.
 *   Status            a new Matter is OPEN, and the only changes the product
 *                     offers are complete and cancel, on the detail page (D-109).
 *   Person in charge  answers to `*.matters.assign` on its own control.
 *   Parent Project    chosen at creation and fixed thereafter — re-parenting
 *                     would move the Matter between Offices.
 *
 * A form that showed those fields and silently dropped them would tell the user
 * their choice was accepted. The API returns 422 for each, so this form does not
 * present a choice that does not exist.
 *
 * Zod covers shape and length for usability. The Form Request stays
 * authoritative, and **no date-ordering rule appears on either side**: nothing
 * canonical says a Matter may not be back-dated or planned in an unusual order,
 * so neither layer invents one.
 */
export function MatterForm({ domain, matter }: { domain: MatterDomain; matter?: Matter }) {
  const t = useTranslations("matters");
  const tActions = useTranslations("actions");
  const locale = useLocale();
  const router = useRouter();
  const queryClient = useQueryClient();

  const isEdit = matter !== undefined;

  // Only offered at creation: the parent is fixed once the Matter exists.
  const projects = useQuery({
    queryKey: projectQueryKeys.list({ page: 1, search: "", status: "", priority: "" }),
    queryFn: () => getProjects({ page: 1, search: "", status: "", priority: "" }),
    enabled: !isEdit,
  });

  // Bounded server-side to this Office, this domain, and active entries.
  const serviceTypes = useQuery({
    queryKey: matterQueryKeys.serviceTypeOptions(domain),
    queryFn: () => getMatterServiceTypeOptions(domain),
  });

  const schema = z.object({
    project_id: isEdit
      ? z.string()
      : z
          .string()
          .trim()
          .min(1, { message: t("validation.projectRequired") }),
    service_type_id: z.string().trim(),
    title: z
      .string()
      .trim()
      .min(1, { message: t("validation.titleRequired") })
      .max(255, { message: t("validation.tooLong") }),
    priority: z.union([z.enum(PROJECT_PRIORITIES), z.literal("")]),
    opened_at: z.string().trim(),
    target_completion_date: z.string().trim(),
    notes: z.string().trim(),
  });

  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      project_id: "",
      service_type_id: "",
      title: "",
      priority: "NORMAL",
      opened_at: "",
      target_completion_date: "",
      notes: "",
    },
  });

  const { reset } = form;

  useEffect(() => {
    if (matter) {
      reset({
        project_id: matter.project?.id ?? "",
        service_type_id: matter.service_type?.id ?? "",
        title: matter.title,
        priority: matter.priority ?? "",
        opened_at: matter.opened_at ?? "",
        target_completion_date: matter.target_completion_date ?? "",
        notes: matter.notes ?? "",
      });
    }
  }, [matter, reset]);

  /** Empty strings become null so an untouched optional field stays absent. */
  const payload = (values: FormValues) => {
    const blank = (value: string) => (value.trim() === "" ? null : value.trim());

    return {
      service_type_id: blank(values.service_type_id),
      title: values.title.trim(),
      priority: values.priority === "" ? null : values.priority,
      opened_at: blank(values.opened_at),
      target_completion_date: blank(values.target_completion_date),
      notes: blank(values.notes),
    };
  };

  const mutation = useMutation({
    mutationFn: async (values: FormValues) => {
      if (matter) {
        return updateMatter(domain, matter.id, payload(values));
      }

      return createMatter(domain, {
        project_id: values.project_id.trim(),
        ...payload(values),
      });
    },
    onSuccess: async (saved) => {
      await queryClient.invalidateQueries({ queryKey: matterQueryKeys.all(domain) });

      router.push(`${matterBasePath(domain)}/${saved.id}`);
    },
    onError: (error: unknown) => {
      form.setError("root", { message: t(`errors.${toMatterErrorKey(error)}`) });
    },
  });

  const onSubmit = form.handleSubmit((values) => {
    form.clearErrors("root");
    mutation.mutate(values);
  });

  return (
    <form onSubmit={onSubmit} noValidate className="flex max-w-2xl flex-col gap-6">
      {form.formState.errors.root ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {form.formState.errors.root.message}
        </p>
      ) : null}

      {!isEdit ? (
        <p className="text-muted-foreground text-sm">{t("createHint")}</p>
      ) : (
        <div className="flex flex-col gap-1">
          <span className="text-sm font-medium">{t("referenceLabel")}</span>
          <span className="text-muted-foreground font-mono text-sm">{matter.matter_number}</span>
          <span className="text-muted-foreground text-xs">{t("referenceHint")}</span>
        </div>
      )}

      {!isEdit ? (
        <div className="flex flex-col gap-2">
          <Label htmlFor="project_id">{t("projectLabel")}</Label>
          <select
            id="project_id"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            {...form.register("project_id")}
          >
            <option value="">{t("selectProject")}</option>
            {(projects.data?.data ?? []).map((project) => (
              <option key={project.id} value={project.id}>
                {project.project_number} — {project.title}
              </option>
            ))}
          </select>
          {form.formState.errors.project_id ? (
            <p role="alert" className="text-destructive text-sm">
              {form.formState.errors.project_id.message}
            </p>
          ) : (
            <p className="text-muted-foreground text-xs">{t("projectHint")}</p>
          )}
        </div>
      ) : null}

      <Field
        id="title"
        label={t("titleLabel")}
        registration={form.register("title")}
        error={form.formState.errors.title?.message}
      />

      <div className="flex flex-col gap-2">
        <Label htmlFor="service_type_id">{t("serviceTypeLabel")}</Label>
        <select
          id="service_type_id"
          className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
          {...form.register("service_type_id")}
        >
          <option value="">{t("noServiceType")}</option>
          {(serviceTypes.data ?? []).map((type) => (
            <option key={type.id} value={type.id}>
              {locale === "en" ? type.name_en : type.name_id}
            </option>
          ))}
        </select>
        <p className="text-muted-foreground text-xs">{t("serviceTypeHint")}</p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2">
          <Label htmlFor="priority">{t("priorityLabel")}</Label>
          <select
            id="priority"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            {...form.register("priority")}
          >
            <option value="">{t("noPriority")}</option>
            {PROJECT_PRIORITIES.map((code) => (
              <option key={code} value={code}>
                {t(`priorities.${code}`)}
              </option>
            ))}
          </select>
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

      <div className="flex flex-col gap-2">
        <Label htmlFor="notes">{t("notesLabel")}</Label>
        <textarea
          id="notes"
          rows={4}
          className="border-border bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
          {...form.register("notes")}
        />
      </div>

      <div>
        <Button type="submit" disabled={mutation.isPending}>
          {mutation.isPending ? tActions("saving") : tActions("save")}
        </Button>
      </div>
    </form>
  );
}

/**
 * One labelled text input.
 *
 * Takes the registration and the message rather than the whole form object,
 * which keeps it fully typed.
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
      <Input id={id} type={type} aria-invalid={error !== undefined} {...registration} />
      {error ? (
        <p role="alert" className="text-destructive text-sm">
          {error}
        </p>
      ) : null}
    </div>
  );
}
