"use client";

import { useTranslations } from "next-intl";

import { useCurrentUser } from "@/features/auth/use-current-user";
import { TasksList } from "@/features/tasks/tasks-list";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * The caller's own live work.
 *
 * **A client component because it needs the signed-in user's id**, which the
 * server page cannot supply without a second round trip — the filter is
 * `assigned_to = me`, and "me" is only known once `/api/v1/me` answers.
 *
 * **Filtered by `assigned_to`, not by Data Scope.** A person may hold `OFFICE`
 * reach and still want their own queue; asking for their tasks explicitly is what
 * makes this page mean "mine" rather than "whatever my scope happens to be". The
 * backend applies the scope on top, so this can only ever narrow.
 *
 * `open=true` because the question is what to do next. Finished work has its own
 * page.
 */
export function MyTasksList() {
  const t = useTranslations("tasks");
  const { data: user } = useCurrentUser();

  if (user === undefined) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        <Skeleton className="h-14 w-full" />
        <Skeleton className="h-14 w-full" />
      </div>
    );
  }

  return (
    <TasksList
      fixedFilter={{ assigned_to: user.id, open: "true" }}
      emptyTitleKey="noAssignedTitle"
      emptyDescriptionKey="noAssignedTasks"
    />
  );
}
