"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { toTaskErrorKey } from "@/features/tasks/task-errors";
import { addTaskComment, getTaskComments, taskQueryKeys } from "@/services/tasks";

/**
 * The conversation on a Task (M5.4, D-119).
 *
 * **Anybody who may read the task may comment**, which is why there is no
 * capability gate here: commenting answers to `tasks.view`, the same code that got
 * the reader onto this page. Requiring `tasks.update` would mean only the people
 * who can change the work may discuss it.
 *
 * **A settled task still accepts remarks.** Explaining why something was closed is
 * the comment most worth having, and it usually arrives just after the closing —
 * so the form stays regardless of status.
 *
 * **No edit and no delete.** A remark records what somebody said at the time, and
 * the backend refuses an update outright. A correction is another comment, which
 * is why the box never disappears.
 */
export function TaskComments({ taskId }: { taskId: string }) {
  const t = useTranslations("tasks");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [draft, setDraft] = useState("");
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const query = useQuery({
    queryKey: taskQueryKeys.comments(taskId),
    queryFn: () => getTaskComments(taskId),
  });

  const mutation = useMutation({
    mutationFn: (comment: string) => addTaskComment(taskId, comment),
    onSuccess: async () => {
      setDraft("");
      setErrorKey(null);
      await queryClient.invalidateQueries({ queryKey: taskQueryKeys.comments(taskId) });
    },
    onError: (error: unknown) => setErrorKey(toTaskErrorKey(error)),
  });

  const comments = query.data ?? [];

  return (
    <section className="flex flex-col gap-4">
      <h2 className="text-lg font-semibold">{t("comments")}</h2>

      {errorKey ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {t(`errors.${errorKey}`)}
        </p>
      ) : null}

      {query.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          <Skeleton className="h-16 w-full" />
        </div>
      ) : query.isError ? (
        <BaseErrorState
          title={t("errorTitle")}
          description={t(`errors.${toTaskErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : comments.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("noComments")}</p>
      ) : (
        <ol className="flex flex-col gap-3">
          {comments.map((comment) => (
            <li key={comment.id} className="border-border rounded-lg border px-4 py-3">
              <div className="flex flex-wrap items-baseline gap-2">
                <span className="text-sm font-medium">{comment.author?.name ?? "—"}</span>
                <span className="text-muted-foreground text-xs">
                  {comment.created_at?.slice(0, 16).replace("T", " ") ?? ""}
                </span>
              </div>
              <p className="text-muted-foreground mt-1 text-sm whitespace-pre-line">
                {comment.comment}
              </p>
            </li>
          ))}
        </ol>
      )}

      <form
        className="flex flex-col gap-2"
        onSubmit={(event) => {
          event.preventDefault();
          setErrorKey(null);

          if (draft.trim() !== "") {
            mutation.mutate(draft.trim());
          }
        }}
      >
        <Label htmlFor="task-comment">{t("addComment")}</Label>
        <textarea
          id="task-comment"
          rows={3}
          maxLength={5000}
          className="border-border bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
        />
        <div>
          <Button type="submit" size="sm" disabled={draft.trim() === "" || mutation.isPending}>
            {mutation.isPending ? tActions("saving") : t("addComment")}
          </Button>
        </div>
      </form>
    </section>
  );
}
