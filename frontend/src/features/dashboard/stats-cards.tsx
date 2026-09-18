"use client";

import { useQuery } from "@tanstack/react-query";
import {
  BriefcaseBusiness,
  CalendarDays,
  FolderOpen,
  TrendingDown,
  TrendingUp,
  UsersRound,
  type LucideIcon,
} from "lucide-react";
import { useTranslations } from "next-intl";

import { Skeleton } from "@/components/ui/skeleton";
import { dashboardQueryKeys, getDashboardStats } from "@/services/dashboard";
import type {
  DashboardStats,
  DashboardTrend,
  DashboardTrendKey,
  ScopedCount,
} from "@/types/dashboard";

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
 * The visual order follows the approved dashboard reference: active work,
 * clients, today's schedule, and documents. Today's schedule remains reserved
 * until the Calendar module exists.
 */
const CARDS: ReadonlyArray<{
  key: DashboardTrendKey;
  label: string;
  icon: LucideIcon;
  tone: "primary" | "ppat" | "warning" | "danger" | "notary";
}> = [
  { key: "active_matters", label: "activeMatters", icon: BriefcaseBusiness, tone: "ppat" },
  { key: "clients", label: "clients", icon: UsersRound, tone: "ppat" },
  { key: "documents", label: "documents", icon: FolderOpen, tone: "notary" },
];

const RESERVED_CARDS: ReadonlyArray<{
  label: string;
  icon: LucideIcon;
  tone: "ppat" | "warning" | "notary";
}> = [{ label: "scheduleToday", icon: CalendarDays, tone: "warning" }];

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
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
      {visible.map(({ key, label, icon, tone }) => (
        <StatCard
          key={key}
          label={t(label)}
          value={query.data[key]}
          icon={icon}
          tone={tone}
          emptyLabel={t("noDataYet")}
          trend={query.data.trends?.[key]}
        />
      ))}
      {RESERVED_CARDS.map(({ label, icon, tone }) => (
        <ReservedStatCard
          key={label}
          label={t(label)}
          status={t("notAvailable")}
          icon={icon}
          tone={tone}
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
  emptyLabel,
  trend,
  className,
}: {
  label: string;
  value: ScopedCount;
  icon: LucideIcon;
  tone: "primary" | "ppat" | "warning" | "danger" | "notary";
  emptyLabel: string;
  trend?: DashboardTrend | null;
  className?: string;
}) {
  const t = useTranslations("dashboard");
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
      className={`shadow-primary/[0.02] flex min-h-28 items-center gap-4 rounded-xl border p-4 shadow-sm transition-all duration-300 ease-out hover:-translate-y-0.5 hover:shadow-md motion-reduce:transition-none motion-reduce:hover:translate-y-0 sm:p-5 ${tones.card} ${className ?? ""}`}
    >
      <span
        className={`flex size-12 shrink-0 items-center justify-center rounded-xl sm:size-14 ${tones.icon}`}
        aria-hidden="true"
      >
        <Icon className="size-6" />
      </span>
      <dl className="flex min-h-full min-w-0 flex-1 flex-col justify-between gap-3">
        <dt className="text-muted-foreground text-sm">{label}</dt>
        {/* Tabular figures so the row of cards lines up rather than shimmying. */}
        <dd className="flex flex-col gap-1">
          <span className="text-3xl font-semibold tracking-tight tabular-nums">{value}</span>
          {value === 0 ? (
            <span className="text-muted-foreground text-xs font-normal">{emptyLabel}</span>
          ) : null}
          {trend ? (
            <TrendIndicator trend={trend} label={t("trendComparedToPreviousPeriod")} />
          ) : null}
        </dd>
      </dl>
    </div>
  );
}

function TrendIndicator({ trend, label }: { trend: DashboardTrend; label: string }) {
  const Icon = trend.direction === "up" ? TrendingUp : TrendingDown;
  const tone = trend.direction === "up" ? "text-emerald-600" : "text-rose-600";

  return (
    <span className={`inline-flex items-center gap-1 text-xs font-medium ${tone}`}>
      <Icon className="size-3.5" aria-hidden="true" />
      {trend.direction === "up" ? "+" : "−"}
      {Math.abs(trend.value)} <span className="text-muted-foreground font-normal">{label}</span>
    </span>
  );
}

/**
 * A truthful reserved position for the Calendar milestone. It does not imply a
 * count, fetch calendar data, or expose records before that module exists.
 */
function ReservedStatCard({
  label,
  status,
  icon: Icon,
  tone,
  className,
}: {
  label: string;
  status: string;
  icon: LucideIcon;
  tone: "ppat" | "warning" | "notary";
  className?: string;
}) {
  const tones = {
    ppat: {
      card: "border-ppat/15 bg-ppat/[0.035]",
      icon: "bg-ppat/12 text-ppat",
    },
    warning: {
      card: "border-warning/15 bg-warning/[0.035]",
      icon: "bg-warning/12 text-warning",
    },
    notary: {
      card: "border-notary/15 bg-notary/[0.035]",
      icon: "bg-notary/10 text-notary",
    },
  }[tone];

  return (
    <div
      className={`shadow-primary/[0.02] flex min-h-28 items-center gap-4 rounded-xl border p-4 shadow-sm transition-all duration-300 ease-out hover:-translate-y-0.5 hover:shadow-md motion-reduce:transition-none motion-reduce:hover:translate-y-0 sm:p-5 ${tones.card} ${className ?? ""}`}
    >
      <span
        className={`flex size-12 shrink-0 items-center justify-center rounded-xl sm:size-14 ${tones.icon}`}
        aria-hidden="true"
      >
        <Icon className="size-6" />
      </span>
      <dl className="flex min-h-full min-w-0 flex-1 flex-col justify-between gap-3">
        <dt className="text-muted-foreground text-sm">{label}</dt>
        <dd className="text-muted-foreground text-sm font-medium">{status}</dd>
      </dl>
    </div>
  );
}
