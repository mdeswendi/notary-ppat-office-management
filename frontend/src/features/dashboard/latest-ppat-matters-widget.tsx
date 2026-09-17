"use client";

import { useQuery } from "@tanstack/react-query";
import { Ellipsis } from "lucide-react";
import { useFormatter, useTranslations } from "next-intl";

import { DashboardPanel } from "@/features/dashboard/dashboard-panel";
import { MatterStatusBadge } from "@/features/matters/matter-badges";
import { Link } from "@/i18n/navigation";
import { dashboardQueryKeys, getDashboardLatestPpatMatters } from "@/services/dashboard";

/** The latest real PPAT matters, occupying the reference layout's main table. */
export function LatestPpatMattersWidget() {
  const t = useTranslations("dashboard");
  const format = useFormatter();

  const query = useQuery({
    queryKey: dashboardQueryKeys.latestPpatMatters(),
    queryFn: getDashboardLatestPpatMatters,
  });

  const matters = query.data ?? [];

  return (
    <DashboardPanel
      title={t("latestMatters")}
      action={
        <Link
          href="/ppat/matters"
          className="text-primary text-sm underline-offset-4 hover:underline"
        >
          {t("viewAll")}
        </Link>
      }
      isPending={query.isPending}
      isError={query.isError}
      unavailable={query.data === null}
      isEmpty={matters.length === 0}
      emptyMessage={t("noLatestMatters")}
      skeletonRows={5}
    >
      <div className="overflow-x-auto">
        <table className="w-full min-w-[46rem] table-fixed border-collapse text-left text-[13px] 2xl:min-w-0">
          <caption className="sr-only">{t("latestMatters")}</caption>
          <colgroup>
            <col className="w-[15%]" />
            <col className="w-[13%]" />
            <col className="w-[24%]" />
            <col className="w-[17%]" />
            <col className="w-[12%]" />
            <col className="w-[14%]" />
            <col className="w-[5%]" />
          </colgroup>
          <thead>
            <tr className="border-border bg-muted/55 border-y">
              <th scope="col" className="px-2 py-2.5 font-medium">
                {t("matterNumber")}
              </th>
              <th scope="col" className="px-2 py-2.5 font-medium">
                {t("openedDate")}
              </th>
              <th scope="col" className="px-2 py-2.5 font-medium">
                {t("matterTitle")}
              </th>
              <th scope="col" className="px-2 py-2.5 font-medium">
                {t("matterParties")}
              </th>
              <th scope="col" className="px-2 py-2.5 font-medium">
                {t("matterStatus")}
              </th>
              <th scope="col" className="px-2 py-2.5 font-medium">
                {t("targetDate")}
              </th>
              <th scope="col" className="px-1 py-2.5 text-center font-medium">
                <span className="sr-only">{t("matterActions")}</span>
              </th>
            </tr>
          </thead>
          <tbody className="divide-border divide-y">
            {matters.map((matter) => (
              <tr key={matter.id} className="hover:bg-muted/25">
                <td className="px-2 py-3 font-medium tabular-nums">
                  <span className="line-clamp-2 break-words">{matter.matter_number}</span>
                </td>
                <td className="text-muted-foreground px-2 py-3 tabular-nums">
                  {formatDate(matter.opened_at, format)}
                </td>
                <td className="px-2 py-3">
                  <Link
                    href={`/ppat/matters/${matter.id}`}
                    className="line-clamp-2 font-medium underline-offset-4 hover:underline"
                  >
                    {matter.title}
                  </Link>
                </td>
                <td className="text-muted-foreground px-2 py-3">
                  <span className="line-clamp-2">{formatParties(matter.primary_parties)}</span>
                </td>
                <td className="px-2 py-3">
                  <MatterStatusBadge
                    status={matter.status}
                    appearance="soft"
                    className="inline-flex min-w-16 justify-center"
                  />
                </td>
                <td className="text-muted-foreground px-2 py-3 tabular-nums">
                  {formatDate(matter.target_completion_date, format)}
                </td>
                <td className="px-1 py-3 text-center">
                  <Link
                    href={`/ppat/matters/${matter.id}`}
                    aria-label={t("openMatter", { title: matter.title })}
                    title={t("openMatter", { title: matter.title })}
                    className="hover:bg-muted focus-visible:ring-ring inline-flex size-7 items-center justify-center rounded-md outline-none focus-visible:ring-2"
                  >
                    <Ellipsis className="size-4" aria-hidden="true" />
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </DashboardPanel>
  );
}

function formatParties(parties: string[]): string {
  return parties.length > 0 ? parties.join(", ") : "—";
}

function formatDate(value: string | null, format: ReturnType<typeof useFormatter>): string {
  if (!value) {
    return "—";
  }

  return format.dateTime(new Date(value), {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
}
