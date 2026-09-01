"use client";

import { useTranslations } from "next-intl";
import type { ReactNode } from "react";

import { Card, CardHeader } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * The shell every Dashboard panel shares (M8.1, D-122).
 *
 * ## `null` data means the panel does not render at all
 *
 * The backend returns `null` for a panel the caller holds no capability for, and
 * `[]` or `0` for one they may see with nothing in it. This component keeps those
 * apart: `unavailable` renders **nothing** — not an empty card, not a "no
 * permission" message — while an empty result renders the panel with its empty
 * state.
 *
 * A card that is permanently blank for a whole role is dead UI, which is the
 * position `MyTasksWidget` has taken since M5.4 and `10_M0_FOUNDATION.md` §57
 * takes on fabricated dashboard content. Announcing the absence would be worse
 * still: it tells somebody what they are missing without letting them act on it.
 *
 * ## Failure is quiet
 *
 * A panel that shouts about its own error beside five working ones is worse than
 * one that says it has nothing. The message is bilingual and generic — no raw
 * server text ever reaches a user (`CLAUDE.md` §48).
 */
export function DashboardPanel({
  title,
  action,
  isPending,
  isError,
  unavailable,
  isEmpty,
  emptyMessage,
  children,
  skeletonRows = 3,
}: {
  title: string;
  action?: ReactNode;
  isPending: boolean;
  isError: boolean;
  unavailable: boolean;
  isEmpty: boolean;
  emptyMessage: string;
  children: ReactNode;
  skeletonRows?: number;
}) {
  const t = useTranslations("dashboard");

  // No capability for this panel: render nothing at all.
  if (!isPending && unavailable) {
    return null;
  }

  return (
    <Card>
      <CardHeader title={title} action={action} />

      {isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          {Array.from({ length: skeletonRows }, (_, index) => (
            <Skeleton key={index} className="h-8 w-full" />
          ))}
        </div>
      ) : isError ? (
        <p className="text-muted-foreground text-sm">{t("panelUnavailable")}</p>
      ) : isEmpty ? (
        <p className="text-muted-foreground text-sm">{emptyMessage}</p>
      ) : (
        children
      )}
    </Card>
  );
}
