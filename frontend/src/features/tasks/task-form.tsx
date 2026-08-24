"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useForm, type UseFormRegisterReturn } from "react-hook-form";
import { z } from "zod";

import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toTaskErrorKey } from "@/features/tasks/task-errors";
import { useRouter } from "@/i18n/navigation";
import { createTask, getTaskOptions, taskQueryKeys } from "@/services/tasks";
import { PROJECT_PRIORITIES } from "@/types/project";

/**
 * Raise a Task.
 *
 * **Five things this form deliberately does not offer**, each because the backend
 * refuses it outright rather than ignoring it:
 *
 *   Office      work is raised in your own Office, never chosen.
 *   Status      a new task is OPEN; completing and cancelling are their own
 *               capabilities with their own buttons on the detail page.
 *   Creator     the person raising it, taken from the session.
 *   Assigner    recorded when the task is assigned, alongside the assignee.
 *   Completion  a task cannot be finished before it exists.
 *
 * A form that showed those and silently dropped them would tell the user their
 * choice was accepted. The API returns 422 for each.
 *
 * **The assignee control is gated on `tasks.assign`**, because assigning at
 * creation is still the assign capability — the endpoint refuses it rather than
 * quietly dropping the assignee, so offering the field to somebody who cannot use
 * it would produce a 403 they could not explain.
 *
 * **A past due date is accepted**, and no rule forbids one. An office records work
 * that was already due — a deadline that slipped, a task entered today for
 * something owed last week. Refusing it would make the product unable to describe
 * the situation it most needs to show.
 */
export function TaskForm({
  projectId,
  matterId,
}: {
  /** Pre-filled when raising work from a Project or Matter page. */
  projectId?: string;
  matterId?: string;
}) {
  const t = useTranslations("tasks");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const options = useQuery({
    queryKey: taskQueryKeys.options(),
    queryFn: getTaskOptions,
  });

  const schema = z.object({
    title: z
      .string()
      .trim()
      .min(1, { message: t("validation.titleRequired") })
      .max(255, { message: t("validation.tooLong") }),
    description: z.string().trim(),
    priority: z.union([z.enum(PROJECT_PRIORITIES), z.literal("")]),
    due_at: z.string().trim(),
    assigned_to: z.string().trim(),
  });

  type FormValues = z.infer<typeof schema>;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      title: "",
      description: "",
      priority: "NORMAL",
      due_at: "",
      assigned_to: "",
    },
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const blank = (value: string) => (value.trim() === "" ? null : value.trim());

      return createTask({
        title: values.title.trim(),
        description: blank(values.description),
        priority: values.priority === "" ? null : values.priority,
        due_at: blank(values.due_at),
        assigned_to: blank(values.assigned_to),
        project_id: projectId ?? null,
        matter_id: matterId ?? null,
      });
    },
    onSuccess: async (saved) => {
      await queryClient.invalidateQueries({ queryKey: taskQueryKeys.all() });

      router.push(`/tasks/${saved.id}`);
    },
    onError: (error: unknown) => {
      form.setError("root", { message: t(`errors.${toTaskErrorKey(error)}`) });
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

      <p className="text-muted-foreground text-sm">{t("createHint")}</p>

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
          <Label htmlFor="priority">{t("priority")}</Label>
          <select
            id="priority"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            {...form.register("priority")}
          >
            {PROJECT_PRIORITIES.map((code) => (
              <option key={code} value={code}>
                {t(`priorities.${code}`)}
              </option>
            ))}
          </select>
        </div>

        <Field
          id="due_at"
          label={t("dueDate")}
          registration={form.register("due_at")}
          error={form.formState.errors.due_at?.message}
          type="date"
        />
      </div>

      <PermissionGuard permission="tasks.assign">
        <div className="flex flex-col gap-2">
          <Label htmlFor="assigned_to">{t("assignedTo")}</Label>
          <select
            id="assigned_to"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            {...form.register("assigned_to")}
          >
            <option value="">{t("unassigned")}</option>
            {(options.data?.assignees ?? []).map((user) => (
              <option key={user.id} value={user.id}>
                {user.name}
              </option>
            ))}
          </select>
          <p className="text-muted-foreground text-xs">{t("assigneeHint")}</p>
        </div>
      </PermissionGuard>

      <div>
        <Button type="submit" disabled={mutation.isPending}>
          {mutation.isPending ? tActions("saving") : tActions("save")}
        </Button>
      </div>
    </form>
  );
}

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
