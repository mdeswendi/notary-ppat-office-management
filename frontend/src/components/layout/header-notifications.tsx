"use client";

import { useQuery } from "@tanstack/react-query";
import { Bell } from "lucide-react";
import { useTranslations } from "next-intl";

import { Link } from "@/i18n/navigation";
import { dashboardQueryKeys, getDashboardTasks } from "@/services/dashboard";

/**
 * A real notification entry point backed by the signed-in user's task queue.
 * No fabricated badge is shown: the count is today's plus overdue work and is
 * omitted while the query is unavailable or genuinely empty.
 */
export function HeaderNotifications({ enabled }: { enabled: boolean }) {
  const t = useTranslations("dashboard.notifications");
  const query = useQuery({
    queryKey: dashboardQueryKeys.tasks(),
    queryFn: getDashboardTasks,
    enabled,
    staleTime: 30_000,
  });

  if (!enabled) {
    return null;
  }

  const count = query.data ? query.data.today.length + query.data.overdue.length : 0;
  const accessibleLabel = count > 0 ? t("withCount", { count }) : t("label");

  return (
    <Link
      href="/tasks/my"
      aria-label={accessibleLabel}
      title={accessibleLabel}
      className="hover:bg-accent focus-visible:ring-ring relative grid size-9 place-items-center rounded-md outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
    >
      <Bell className="size-[1.125rem]" aria-hidden="true" />
      {count > 0 ? (
        <span
          aria-hidden="true"
          className="bg-destructive text-destructive-foreground absolute -top-0.5 -right-0.5 grid min-w-4 place-items-center rounded-full px-1 text-[10px] leading-4 font-semibold tabular-nums"
        >
          {count > 99 ? "99+" : count}
        </span>
      ) : null}
    </Link>
  );
}
