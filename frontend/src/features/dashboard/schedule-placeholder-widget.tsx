"use client";

import { CalendarDays } from "lucide-react";
import { useTranslations } from "next-intl";

import { Card, CardHeader } from "@/components/ui/card";
import { useCurrentUser } from "@/features/auth/use-current-user";
import { can } from "@/lib/permissions/can";

/**
 * Holds the reference layout's schedule position until the Calendar milestone
 * supplies real events. It is explicitly unavailable rather than an invented
 * empty agenda, so readers cannot mistake roadmap UI for synchronized records.
 */
export function SchedulePlaceholderWidget() {
  const t = useTranslations("dashboard");
  const { data: user } = useCurrentUser();

  if (!can(user, "tasks.view")) {
    return null;
  }

  return (
    <Card className="border-border/80 bg-card/96 shadow-primary/[0.025] p-4 shadow-sm sm:p-5">
      <CardHeader title={t("scheduleToday")} description={t("scheduleDescription")} />

      <div className="border-warning/20 bg-warning/[0.045] flex min-h-28 items-center gap-3 rounded-lg border p-4">
        <span
          className="bg-warning/12 text-warning flex size-10 shrink-0 items-center justify-center rounded-lg"
          aria-hidden="true"
        >
          <CalendarDays className="size-5" />
        </span>
        <div>
          <p className="font-medium">{t("notAvailable")}</p>
          <p className="text-muted-foreground mt-1 text-xs leading-relaxed">
            {t("scheduleUnavailableDescription")}
          </p>
        </div>
      </div>
    </Card>
  );
}
