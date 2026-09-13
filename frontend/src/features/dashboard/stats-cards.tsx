"use client";

import { useQuery } from "@tanstack/react-query";
import {
  BriefcaseBusiness,
  ClipboardCheck,
  ClockAlert,
  FileSignature,
  FolderKanban,
  type LucideIcon,
} from "lucide-react";
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
const CARDS: ReadonlyArray<{
  key: keyof DashboardStats;
  label: string;
  icon: LucideIcon;
  tone: "primary" | "ppat" | "warning" | "danger" | "notary";
}> = [
  { key: "active_projects", label: "activeProjects", icon: FolderKanban, tone: "primary" },
  { key: "active_matters", label: "activeMatters", icon: BriefcaseBusiness, tone: "ppat" },
  { key: "pending_reviews", label: "pendingReviews", icon: ClipboardCheck, tone: "warning" },
  { key: "overdue_tasks", label: "overdueTasks", icon: ClockAlert, tone: "danger" },
  { key: "total_deeds_this_month", label: "totalDeeds", icon: FileSignature, tone: "notary" },
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
        className="grid grid-cols-2 gap-3 sm:grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] sm:gap-4"
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
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] sm:gap-4">
      {visible.map(({ key, label, icon, tone }, index) => (
        <StatCard
          key={key}
          label={t(label)}
          value={query.data[key]}
          icon={icon}
          tone={tone}
          className={
            visible.length % 2 === 1 && index === visible.length - 1
              ? "col-span-2 sm:col-span-1"
              : undefined
          }
        />
      ))}
    </div>
  );
}

function StatCard({
  label,
  value,
  icon: Icon,
  tone,
  className,
}: {
  label: string;
  value: ScopedCount;
  icon: LucideIcon;
  tone: "primary" | "ppat" | "warning" | "danger" | "notary";
  className?: string;
}) {
  const tones = {
    primary: {
      card: "border-primary/10 bg-primary/[0.035]",
      icon: "bg-info/10 text-info",
    },
    ppat: {
      card: "border-ppat/15 bg-ppat/[0.045]",
      icon: "bg-ppat/12 text-ppat",
    },
    warning: {
      card: "border-warning/15 bg-warning/[0.045]",
      icon: "bg-warning/12 text-warning",
    },
    danger: {
      card: "border-destructive/15 bg-destructive/[0.035]",
      icon: "bg-destructive/10 text-destructive",
    },
    notary: {
      card: "border-notary/15 bg-notary/[0.035]",
      icon: "bg-notary/10 text-notary",
    },
  }[tone];

  return (
    <div
      className={`shadow-primary/[0.02] flex min-h-28 items-start justify-between gap-3 rounded-lg border p-4 shadow-sm sm:p-5 ${tones.card} ${className ?? ""}`}
    >
      <dl className="flex min-h-full flex-col justify-between gap-3">
        <dt className="text-muted-foreground text-sm">{label}</dt>
        {/* Tabular figures so the row of cards lines up rather than shimmying. */}
        <dd className="text-3xl font-semibold tracking-tight tabular-nums">{value}</dd>
      </dl>
      <div className="flex items-start justify-between gap-3">
        <span
          className={`flex size-9 shrink-0 items-center justify-center rounded-lg ${tones.icon}`}
          aria-hidden="true"
        >
          <Icon className="size-[1.125rem]" />
        </span>
      </div>
    </div>
  );
}
