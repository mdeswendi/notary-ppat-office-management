"use client";

import { useEffect } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useForm, type UseFormRegisterReturn } from "react-hook-form";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toProjectErrorKey } from "@/features/projects/project-errors";
import { useRouter } from "@/i18n/navigation";
import { createProject, projectQueryKeys, updateProject } from "@/services/projects";
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
export function ProjectForm({ project }: { project?: Project }) {
  const t = useTranslations("projects");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const isEdit = project !== undefined;

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

      return createProject(payload(values));
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

      <div className="flex flex-col gap-2">
        <Label htmlFor="description">{t("descriptionLabel")}</Label>
        <textarea
          id="description"
          rows={4}
          className="border-border bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
          {...form.register("description")}
        />
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
 * which keeps it fully typed — passing `UseFormReturn` through a generic
 * boundary is where an `any` usually creeps in (CLAUDE.md section 53).
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
