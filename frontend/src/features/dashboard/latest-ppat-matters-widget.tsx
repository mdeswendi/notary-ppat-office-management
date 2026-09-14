"use client";

import { useQuery } from "@tanstack/react-query";
import { useFormatter, useTranslations } from "next-intl";

import { useCurrentUser } from "@/features/auth/use-current-user";
import { DashboardPanel } from "@/features/dashboard/dashboard-panel";
import { MatterStatusBadge } from "@/features/matters/matter-badges";
import { Link } from "@/i18n/navigation";
import { can } from "@/lib/permissions/can";
import { getMatters, matterQueryKeys } from "@/services/matters";

const LATEST_QUERY = {
  page: 1,
  per_page: 5,
  search: "",
  status: "" as const,
  priority: "" as const,
  project_id: "",
};

/** The latest real PPAT matters, occupying the reference layout's main table. */
export function LatestPpatMattersWidget() {
  const t = useTranslations("dashboard");
  const format = useFormatter();
  const { data: user } = useCurrentUser();
  const canView = can(user, "ppat.matters.view");

  const query = useQuery({
    queryKey: matterQueryKeys.list("PPAT", LATEST_QUERY),
    queryFn: () => getMatters("PPAT", LATEST_QUERY),
    enabled: canView,
  });

  if (!canView) {
    return null;
  }

  const matters = query.data?.data ?? [];

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
      unavailable={false}
      isEmpty={matters.length === 0}
      emptyMessage={t("noLatestMatters")}
      skeletonRows={5}
    >
      <div className="overflow-x-auto">
        <table className="w-full min-w-[42rem] border-collapse text-left text-sm">
          <thead>
            <tr className="border-border bg-muted/55 border-y">
              <th className="px-3 py-2.5 font-medium">{t("matterNumber")}</th>
              <th className="px-3 py-2.5 font-medium">{t("openedDate")}</th>
              <th className="px-3 py-2.5 font-medium">{t("matterTitle")}</th>
              <th className="px-3 py-2.5 font-medium">{t("matterStatus")}</th>
              <th className="px-3 py-2.5 font-medium">{t("targetDate")}</th>
            </tr>
          </thead>
          <tbody className="divide-border divide-y">
            {matters.map((matter) => (
              <tr key={matter.id}>
                <td className="px-3 py-3 font-medium tabular-nums">
                  <Link href={`/ppat/matters/${matter.id}`} className="hover:underline">
                    {matter.matter_number}
                  </Link>
                </td>
                <td className="text-muted-foreground px-3 py-3 whitespace-nowrap tabular-nums">
                  {formatDate(matter.opened_at, format)}
                </td>
                <td className="px-3 py-3">
                  <span className="line-clamp-1 font-medium">{matter.title}</span>
                  {matter.service_type ? (
                    <span className="text-muted-foreground mt-0.5 block text-xs">
                      {matter.service_type.name_id}
                    </span>
                  ) : null}
                </td>
                <td className="px-3 py-3">
                  <MatterStatusBadge status={matter.status} />
                </td>
                <td className="text-muted-foreground px-3 py-3 whitespace-nowrap tabular-nums">
                  {formatDate(matter.target_completion_date, format)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </DashboardPanel>
  );
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
