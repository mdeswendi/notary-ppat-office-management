"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Skeleton } from "@/components/ui/skeleton";
import { dashboardQueryKeys, getDashboardStats } from "@/services/dashboard";
import type { DashboardStats, ScopedCount } from "@/types/dashboard";

/**
 * The headline figures (M8.1, D-122).
 *
 * **A card whose figure is `null` is not rendered.** `null` means the caller
 * holds no capability for the resource behind it, and a zero in its place would
 * be a lie about records they are not entitled to count — the position O-046 took
 * when it refused to report a document count of zero for a junction that does not
 * exist.
 *
 * When every figure is `null` the row disappears entirely, which is what D-122
 * means by an actor holding nothing seeing a Dashboard with no panels.
 *
 * ## The row has no fixed column count, and cannot have one
 *
 * It used to be `xl:grid-cols-4` for a list of **five** cards, so a reader who
 * could see all five got four across and the fifth stranded on its own row
 * beside three empty slots — the hole in the middle of the Dashboard.
 *
 * A fixed five would only move the problem: the count is whatever the caller's
 * capabilities allow, anywhere from one to five, so any fixed number is wrong
 * for some reader. `auto-fit` lays out as many tracks as fit and stretches the
 * ones it has, which is right for every count.
 */
const CARDS: ReadonlyArray<{ key: keyof DashboardStats; label: string }> = [
  { key: "active_projects", label: "activeProjects" },
  { key: "active_matters", label: "activeMatters" },
  { key: "pending_reviews", label: "pendingReviews" },
  { key: "overdue_tasks", label: "overdueTasks" },
  { key: "total_deeds_this_month", label: "totalDeeds" },
];

export function StatsCards() {
  const t = useTranslations("dashboard");

  const query = useQuery({
    queryKey: dashboardQueryKeys.stats(),
    queryFn: getDashboardStats,
  });

  if (query.isPending) {
    return (
      <div
        className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-4"
        aria-busy="true"
        aria-live="polite"
      >
        <span className="sr-only">{t("loading")}</span>
        {Array.from({ length: 4 }, (_, index) => (
          <Skeleton key={index} className="h-24 w-full" />
        ))}
      </div>
    );
  }

  if (query.isError) {
    return <p className="text-muted-foreground text-sm">{t("panelUnavailable")}</p>;
  }

  const visible = CARDS.filter(({ key }) => query.data[key] !== null);

  if (visible.length === 0) {
    return null;
  }

  return (
    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-4">
      {visible.map(({ key, label }) => (
        <StatCard key={key} label={t(label)} value={query.data[key]} />
      ))}
    </div>
  );
}

function StatCard({ label, value }: { label: string; value: ScopedCount }) {
  return (
    <div className="border-border bg-card flex flex-col gap-1 rounded-lg border p-5">
      <span className="text-muted-foreground text-sm">{label}</span>
      {/* Tabular figures so the row of cards lines up rather than shimmying. */}
      <span className="text-2xl font-semibold tabular-nums">{value}</span>
    </div>
  );
}
