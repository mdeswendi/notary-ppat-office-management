"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { DashboardPanel } from "@/features/dashboard/dashboard-panel";
import { dashboardQueryKeys, getDashboardActivity } from "@/services/dashboard";
import type { ActivityItem } from "@/types/dashboard";

/**
 * The recent timeline (M8.1, D-123).
 *
 * ## It starts empty, and that is not a bug
 *
 * Nothing is backfilled: seven milestones of work happened before `activities`
 * existed and those events were not recorded, so manufacturing rows for them
 * would put fabricated timestamps into a factual record. An office that upgrades
 * today sees the empty state until the next thing happens, and the copy says so
 * rather than looking broken.
 *
 * ## Every row is a translation key
 *
 * The server sends `activity.types.DEED_APPROVED` and its interpolation values,
 * never a rendered sentence — choosing the language belongs to the client in a
 * bilingual product (`CLAUDE.md` §6). An unknown type falls back to the raw code
 * rather than throwing, so a server that learns a new event before the frontend
 * does degrades to something readable.
 */
export function ActivityWidget() {
  const t = useTranslations("dashboard");
  const types = useTranslations("activity.types");

  const query = useQuery({
    queryKey: dashboardQueryKeys.activity(20),
    queryFn: () => getDashboardActivity(20),
  });

  const items = query.data ?? [];

  return (
    <DashboardPanel
      title={t("activity")}
      isPending={query.isPending}
      isError={query.isError}
      unavailable={false}
      isEmpty={items.length === 0}
      emptyMessage={t("noActivity")}
    >
      <ol className="divide-border divide-y text-sm">
        {items.map((item) => (
          <li key={item.id} className="flex flex-col gap-0.5 py-2 first:pt-0 last:pb-0">
            <span>
              <span className="font-medium">{item.actor?.name ?? t("systemActor")}</span>{" "}
              {describe(item, types)}
            </span>

            <span className="text-muted-foreground text-xs tabular-nums">
              {item.created_at?.slice(0, 16).replace("T", " ") ?? "—"}
            </span>
          </li>
        ))}
      </ol>
    </DashboardPanel>
  );
}

/**
 * Resolve one entry's sentence, falling back to its raw code.
 *
 * `has()` is checked rather than letting next-intl throw: a backend that gains an
 * `ActivityType` before the messages catch up should degrade to a readable code,
 * not blank the whole panel.
 */
function describe(item: ActivityItem, types: ReturnType<typeof useTranslations>): string {
  if (!types.has(item.activity_type)) {
    return item.activity_type;
  }

  // Null values are dropped rather than passed through: next-intl interpolates
  // strings, numbers and dates, and a null placeholder would throw at render.
  // A missing value leaves its placeholder visible, which is the honest failure
  // — better than blanking a whole panel over one absent reference number.
  const values: Record<string, string | number> = {};

  for (const [key, value] of Object.entries(item.metadata ?? {})) {
    if (value !== null) {
      values[key] = value;
    }
  }

  return types(item.activity_type, values);
}
