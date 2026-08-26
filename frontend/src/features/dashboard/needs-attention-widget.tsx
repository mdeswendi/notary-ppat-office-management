"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { DashboardPanel } from "@/features/dashboard/dashboard-panel";
import { Link } from "@/i18n/navigation";
import { dashboardQueryKeys, getDashboardNeedsAttention } from "@/services/dashboard";
import type { NeedsAttentionItem } from "@/types/dashboard";

/**
 * What is stalled (M8.1).
 *
 * **`days_waiting` is shown, never used as a threshold.** The server includes
 * everything that is actually waiting, actually under review, or actually past
 * its due date; how long is too long is an office judgement, and this panel gives
 * the reader the number rather than deciding for them.
 *
 * Ordering comes from the server — longest-waiting first — so nothing here
 * re-sorts.
 */
export function NeedsAttentionWidget() {
  const t = useTranslations("dashboard");

  const query = useQuery({
    queryKey: dashboardQueryKeys.needsAttention(),
    queryFn: getDashboardNeedsAttention,
  });

  const items = query.data ?? null;

  return (
    <DashboardPanel
      title={t("needsAttention")}
      isPending={query.isPending}
      isError={query.isError}
      unavailable={items === null}
      isEmpty={items?.length === 0}
      emptyMessage={t("nothingNeedsAttention")}
    >
      <ul className="divide-border divide-y text-sm">
        {(items ?? []).map((item) => (
          <li
            key={`${item.type}-${item.id}`}
            className="flex flex-col gap-1 py-2 first:pt-0 last:pb-0"
          >
            <div className="flex flex-wrap items-center gap-2">
              <AttentionLink item={item} />

              {/* Status is carried as text as well as position, so the panel does
                  not rely on colour alone (CLAUDE.md §49). */}
              <span className="text-muted-foreground shrink-0 text-xs">
                {t(`attention.${item.type}`)}
              </span>
            </div>

            <span className="text-muted-foreground text-xs">
              {item.reference ? `${item.reference} · ` : ""}
              {item.days_waiting === null
                ? t("attention.noAge")
                : t("attention.daysWaiting", { days: item.days_waiting })}
            </span>
          </li>
        ))}
      </ul>
    </DashboardPanel>
  );
}

/**
 * Links only where a destination exists.
 *
 * A Matter's page needs its domain to build a URL and the payload does not carry
 * one for every type, so an unlinkable row renders as plain text rather than as a
 * link to a 404 — the D-064 discipline applied to a widget.
 */
function AttentionLink({ item }: { item: NeedsAttentionItem }) {
  if (item.type === "TASK_OVERDUE") {
    return (
      <Link
        href={`/tasks/${item.id}`}
        className="min-w-0 flex-1 truncate underline-offset-4 hover:underline"
      >
        {item.title}
      </Link>
    );
  }

  if (item.type === "DEED_PENDING_REVIEW" && item.domain) {
    return (
      <Link
        href={`/${item.domain}/deeds/${item.id}`}
        className="min-w-0 flex-1 truncate underline-offset-4 hover:underline"
      >
        {item.title}
      </Link>
    );
  }

  return <span className="min-w-0 flex-1 truncate">{item.title}</span>;
}
