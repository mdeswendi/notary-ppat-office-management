"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { DashboardPanel } from "@/features/dashboard/dashboard-panel";
import { dashboardQueryKeys, getDashboardWorkload } from "@/services/dashboard";

/**
 * Who is carrying how much (M8.1).
 *
 * **Nobody is listed by role.** The server builds this from actual assignments
 * for whoever the caller may read under `users.view` — a role-name filter would
 * be the authorization pattern D-048 forbids, and would also be wrong on its own
 * terms, since who holds which role is configuration an office changes.
 *
 * The bar is proportional to the busiest person on the list rather than to any
 * fixed capacity: this office has no notion of how much work a person should
 * have, and drawing one would assert a policy nobody has written down.
 */
export function WorkloadWidget() {
  const t = useTranslations("dashboard");

  const query = useQuery({
    queryKey: dashboardQueryKeys.workload(),
    queryFn: getDashboardWorkload,
  });

  const rows = query.data ?? null;
  const busiest = Math.max(1, ...(rows ?? []).map((row) => row.matter_count + row.task_count));

  return (
    <DashboardPanel
      title={t("workload")}
      isPending={query.isPending}
      isError={query.isError}
      unavailable={rows === null}
      isEmpty={rows?.length === 0}
      emptyMessage={t("noWorkload")}
    >
      <ul className="flex flex-col gap-3 text-sm">
        {(rows ?? []).map((row) => {
          const total = row.matter_count + row.task_count;

          return (
            <li key={row.user_id} className="flex flex-col gap-1">
              <div className="flex items-baseline justify-between gap-2">
                <span className="min-w-0 truncate">{row.user_name}</span>

                <span className="text-muted-foreground shrink-0 text-xs tabular-nums">
                  {t("workloadCounts", {
                    matters: row.matter_count,
                    tasks: row.task_count,
                  })}
                </span>
              </div>

              {/* Decorative: the counts beside it carry the same information as
                  text, so a reader who cannot see the bar loses nothing. */}
              <div className="bg-muted h-1.5 overflow-hidden rounded-full" aria-hidden="true">
                <div
                  className="bg-primary h-full rounded-full"
                  style={{ width: `${Math.round((total / busiest) * 100)}%` }}
                />
              </div>
            </li>
          );
        })}
      </ul>
    </DashboardPanel>
  );
}
