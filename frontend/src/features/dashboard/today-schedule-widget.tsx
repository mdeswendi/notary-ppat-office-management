"use client";

import { CalendarDays } from "lucide-react";
import { useFormatter, useTranslations } from "next-intl";
import { Card, CardHeader } from "@/components/ui/card";
import { useQuery } from "@tanstack/react-query";
import { dashboardQueryKeys, getDashboardTodaySchedule } from "@/services/dashboard";

export function TodayScheduleWidget() {
  const t = useTranslations("dashboard");
  const format = useFormatter();
  const query = useQuery({
    queryKey: [...dashboardQueryKeys.all(), "today-schedule"],
    queryFn: getDashboardTodaySchedule,
  });

  if (query.isPending)
    return (
      <Card className="p-5">
        <CardHeader title={t("scheduleToday")} description={t("scheduleDescription")} />
        <p className="text-muted-foreground text-sm">{t("loading")}</p>
      </Card>
    );
  if (query.data === null) return null;
  const events = query.data ?? [];

  return (
    <Card className="border-border/80 bg-card/96 p-4 shadow-sm sm:p-5">
      <CardHeader title={t("scheduleToday")} description={t("scheduleDescription")} />
      {events.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("noScheduleToday")}</p>
      ) : (
        <ul className="divide-border divide-y">
          {events.map((event) => (
            <li key={event.id} className="flex gap-3 py-3 first:pt-0 last:pb-0">
              <CalendarDays className="text-warning mt-0.5 size-4 shrink-0" aria-hidden="true" />
              <div className="min-w-0 text-sm">
                <p className="font-medium">{event.title}</p>
                <p className="text-muted-foreground text-xs">
                  {format.dateTime(new Date(event.starts_at), {
                    hour: "2-digit",
                    minute: "2-digit",
                  })}
                  {event.location ? ` · ${event.location}` : ""}
                </p>
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
