"use client";

import { useQuery } from "@tanstack/react-query";
import { BriefcaseBusiness, UserRound } from "lucide-react";
import { useTranslations } from "next-intl";

import { DashboardPanel } from "@/features/dashboard/dashboard-panel";
import { useCurrentUser } from "@/features/auth/use-current-user";
import { Link } from "@/i18n/navigation";
import { can } from "@/lib/permissions/can";
import { getOfficePractice, officePracticeKeys } from "@/services/office-practice";

/**
 * The active professional context of this Office.
 *
 * `offices.view` is checked before the query is enabled, so the Dashboard does
 * not probe an endpoint the current actor may not read. Laravel authorizes the
 * endpoint again; this check only keeps the interface and network activity
 * honest.
 */
export function ProfessionalCollaboratorsWidget() {
  const t = useTranslations("dashboard");
  const { data: user, isPending: userPending } = useCurrentUser();
  const permitted = can(user, "offices.view");

  const query = useQuery({
    queryKey: officePracticeKeys.detail,
    queryFn: getOfficePractice,
    enabled: permitted,
    retry: false,
  });

  if (!userPending && !permitted) {
    return null;
  }

  const appointments = query.data?.professionals.filter((item) => item.is_active) ?? [];

  return (
    <DashboardPanel
      title={t("professionalCollaborators")}
      description={t("professionalCollaboratorsDescription")}
      action={
        <Link
          href="/settings/office-practice"
          className="text-muted-foreground hover:text-foreground text-sm underline-offset-4 hover:underline"
        >
          {t("viewAll")}
        </Link>
      }
      isPending={userPending || query.isPending}
      isError={query.isError}
      unavailable={false}
      isEmpty={appointments.length === 0}
      emptyMessage={t("noProfessionalCollaborators")}
    >
      <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-1">
        {appointments.slice(0, 4).map((appointment) => {
          const internal = appointment.relationship_type === "INTERNAL";

          return (
            <li
              key={appointment.id}
              className="border-border/80 bg-muted/35 flex items-start gap-3 rounded-lg border p-3"
            >
              <span
                aria-hidden="true"
                className={
                  internal
                    ? "bg-ppat/10 text-ppat grid size-9 shrink-0 place-items-center rounded-lg"
                    : "bg-notary/10 text-notary grid size-9 shrink-0 place-items-center rounded-lg"
                }
              >
                {internal ? (
                  <UserRound className="size-4" />
                ) : (
                  <BriefcaseBusiness className="size-4" />
                )}
              </span>

              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">
                  {appointment.individual.display_name ?? t("unnamedProfessional")}
                </p>
                <p className="text-muted-foreground mt-0.5 text-xs">
                  {t(`professionTypes.${appointment.profession_type}`)} ·{" "}
                  {t(`relationshipTypes.${appointment.relationship_type}`)}
                </p>
                {appointment.professional_office_name ? (
                  <p className="text-muted-foreground mt-1 line-clamp-1 text-xs">
                    {appointment.professional_office_name}
                  </p>
                ) : null}
              </div>
            </li>
          );
        })}
      </ul>
    </DashboardPanel>
  );
}
