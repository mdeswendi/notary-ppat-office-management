"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { TaskOverdueBadge, TaskPriorityBadge, TaskStatusBadge } from "@/features/tasks/task-badges";
import { TaskComments } from "@/features/tasks/task-comments";
import { toTaskErrorKey } from "@/features/tasks/task-errors";
import { Link, useRouter } from "@/i18n/navigation";
import {
  assignTask,
  cancelTask,
  completeTask,
  deleteTask,
  getTask,
  getTaskOptions,
  reopenTask,
  taskQueryKeys,
  updateTask,
} from "@/services/tasks";
import { TASK_EDITABLE_STATUSES } from "@/types/task";

/**
 * One Task, and the acts its capabilities allow.
 *
 * **Every control is gated on a backend-computed flag**, not on a permission
 * string the browser assembled. The flags fold in **status eligibility as well as
 * capability**, so a control that would answer 422 is simply absent: `can_reopen`
 * is false on live work, `can_delete` false on anything still in flight,
 * `can_update` false on a settled task.
 *
 * **Six separate capabilities, six separate controls.** Completing, reopening,
 * cancelling, deleting, editing the status and reassigning each answer to their
 * own code — `tasks.reopen` in particular is not part of `tasks.complete`, which
 * the plan proposed and the registry contradicts.
 *
 * **The status control offers three values, not five.** `COMPLETED` and
 * `CANCELLED` are decisions with their own buttons; a dropdown that silently
 * failed for two of its five options would be dishonest.
 */
export function TaskDetail({ taskId }: { taskId: string }) {
  const t = useTranslations("tasks");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();
  const router = useRouter();

  const [actionError, setActionError] = useState<string | null>(null);

  const query = useQuery({
    queryKey: taskQueryKeys.detail(taskId),
    queryFn: () => getTask(taskId),
  });

  const task = query.data;

  const options = useQuery({
    queryKey: taskQueryKeys.options(),
    queryFn: getTaskOptions,
    enabled: task?.can_assign === true,
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: taskQueryKeys.all() });
  };

  const onError = (error: unknown) => setActionError(t(`errors.${toTaskErrorKey(error)}`));

  const complete = useMutation({
    mutationFn: () => completeTask(taskId),
    onSuccess: invalidate,
    onError,
  });

  const reopen = useMutation({
    mutationFn: () => reopenTask(taskId),
    onSuccess: invalidate,
    onError,
  });

  const cancel = useMutation({
    mutationFn: () => cancelTask(taskId),
    onSuccess: invalidate,
    onError,
  });

  const remove = useMutation({
    mutationFn: () => deleteTask(taskId),
    onSuccess: async () => {
      await invalidate();
      router.push("/tasks");
    },
    onError,
  });

  const assign = useMutation({
    mutationFn: (userId: string) => assignTask(taskId, userId === "" ? null : userId),
    onSuccess: invalidate,
    onError,
  });

  const changeStatus = useMutation({
    mutationFn: (status: (typeof TASK_EDITABLE_STATUSES)[number]) => updateTask(taskId, { status }),
    onSuccess: invalidate,
    onError,
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  if (query.isError || !task) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toTaskErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">{task.title}</h1>
          <TaskStatusBadge status={task.status} />
          <TaskPriorityBadge priority={task.priority} />
          <TaskOverdueBadge isOverdue={task.is_overdue} />
        </div>

        {actionError ? (
          <p
            role="alert"
            className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
          >
            {actionError}
          </p>
        ) : null}

        <div className="flex flex-wrap gap-2">
          {task.can_complete ? (
            <Button
              variant="outline"
              disabled={complete.isPending}
              onClick={() => {
                setActionError(null);
                complete.mutate();
              }}
            >
              {t("complete")}
            </Button>
          ) : null}

          {task.can_reopen ? (
            <Button
              variant="outline"
              disabled={reopen.isPending}
              onClick={() => {
                setActionError(null);
                reopen.mutate();
              }}
            >
              {t("reopen")}
            </Button>
          ) : null}

          {task.can_cancel ? (
            <Button
              variant="outline"
              disabled={cancel.isPending}
              onClick={() => {
                setActionError(null);
                cancel.mutate();
              }}
            >
              {t("cancel")}
            </Button>
          ) : null}

          {task.can_delete ? (
            <Button
              variant="outline"
              disabled={remove.isPending}
              onClick={() => {
                setActionError(null);

                // Nothing in the product undoes this — there is no restore
                // endpoint — so it is confirmed rather than one click.
                if (window.confirm(t("deleteConfirm"))) {
                  remove.mutate();
                }
              }}
            >
              {t("delete")}
            </Button>
          ) : null}
        </div>
      </header>

      <section className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t("detail")}</h2>

        <dl className="border-border grid gap-x-8 gap-y-3 rounded-lg border p-4 sm:grid-cols-2">
          <Detail label={t("dueDate")} value={task.due_at?.slice(0, 10) ?? "—"} />
          <Detail label={t("assignedTo")} value={task.assigned_to?.name ?? t("unassigned")} />
          <Detail label={t("createdBy")} value={task.created_by?.name ?? "—"} />
          <Detail label={t("completedAt")} value={task.completed_at?.slice(0, 10) ?? "—"} />

          {task.project ? (
            <div className="flex flex-col gap-1">
              <dt className="text-sm font-medium">{t("projectLabel")}</dt>
              <dd className="text-sm">
                <Link
                  href={`/projects/${task.project.id}`}
                  className="underline-offset-4 hover:underline"
                >
                  {task.project.project_number} — {task.project.title}
                </Link>
              </dd>
            </div>
          ) : null}

          {task.matter ? (
            <div className="flex flex-col gap-1">
              <dt className="text-sm font-medium">{t("matterLabel")}</dt>
              <dd className="text-sm">
                <Link
                  href={
                    task.matter.domain === "PPAT"
                      ? `/ppat/matters/${task.matter.id}`
                      : `/notary/matters/${task.matter.id}`
                  }
                  className="underline-offset-4 hover:underline"
                >
                  {task.matter.matter_number} — {task.matter.title}
                </Link>
              </dd>
            </div>
          ) : null}

          {task.description ? (
            <div className="flex flex-col gap-1 sm:col-span-2">
              <dt className="text-sm font-medium">{t("descriptionLabel")}</dt>
              <dd className="text-muted-foreground text-sm whitespace-pre-line">
                {task.description}
              </dd>
            </div>
          ) : null}
        </dl>
      </section>

      {task.can_update ? (
        <section className="border-border flex flex-col gap-3 rounded-lg border p-4">
          <h3 className="text-sm font-medium">{t("progressTitle")}</h3>
          <p className="text-muted-foreground text-xs">{t("progressHint")}</p>

          <div className="flex flex-col gap-2 sm:max-w-56">
            <Label htmlFor="task-status">{t("status")}</Label>
            <Select
              id="task-status"
              value={
                TASK_EDITABLE_STATUSES.includes(
                  task.status as (typeof TASK_EDITABLE_STATUSES)[number],
                )
                  ? task.status
                  : ""
              }
              disabled={changeStatus.isPending}
              onChange={(event) => {
                setActionError(null);
                changeStatus.mutate(event.target.value as (typeof TASK_EDITABLE_STATUSES)[number]);
              }}
            >
              {TASK_EDITABLE_STATUSES.map((code) => (
                <option key={code} value={code}>
                  {t(`statuses.${code}`)}
                </option>
              ))}
            </Select>
          </div>
        </section>
      ) : null}

      {task.can_assign ? (
        <section className="border-border flex flex-col gap-3 rounded-lg border p-4">
          <h3 className="text-sm font-medium">{t("assignTitle")}</h3>
          <p className="text-muted-foreground text-xs">{t("assigneeHint")}</p>

          <div className="flex flex-col gap-2 sm:max-w-72">
            <Label htmlFor="task-assignee">{t("assignedTo")}</Label>
            <Select
              id="task-assignee"
              value={task.assigned_to?.id ?? ""}
              disabled={assign.isPending}
              onChange={(event) => {
                setActionError(null);
                assign.mutate(event.target.value);
              }}
            >
              <option value="">{t("unassigned")}</option>
              {(options.data?.assignees ?? []).map((user) => (
                <option key={user.id} value={user.id}>
                  {user.name}
                </option>
              ))}
            </Select>
          </div>
        </section>
      ) : null}

      <TaskComments taskId={task.id} />
    </div>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-sm font-medium">{label}</dt>
      <dd className="text-muted-foreground text-sm">{value}</dd>
    </div>
  );
}
