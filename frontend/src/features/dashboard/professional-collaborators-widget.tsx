"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowRight } from "lucide-react";
import { useTranslations } from "next-intl";

import { DashboardPanel } from "@/features/dashboard/dashboard-panel";
import { useCurrentUser } from "@/features/auth/use-current-user";
import { Link } from "@/i18n/navigation";
import { can } from "@/lib/permissions/can";
import { getOfficePractice, officePracticeKeys } from "@/services/office-practice";
import type {
  ProfessionalAppointment,
  ProfessionalRelationshipType,
  ProfessionType,
} from "@/types/office-practice";

type ProfessionalSummary = {
  id: string;
  displayName: string | null;
  professionTypes: ProfessionType[];
  relationshipType: ProfessionalRelationshipType;
};

/** One person may hold separate Notary and PPAT appointments. The Dashboard
 * presents the collaborator once and combines those professional titles; the
 * underlying appointment histories remain separate and unchanged. */
export function summarizeProfessionals(
  appointments: ProfessionalAppointment[],
): ProfessionalSummary[] {
  const summaries = new Map<string, ProfessionalSummary>();

  for (const appointment of appointments) {
    const key = `${appointment.relationship_type}:${appointment.individual.id}`;
    const existing = summaries.get(key);

    if (existing) {
      if (!existing.professionTypes.includes(appointment.profession_type)) {
        existing.professionTypes.push(appointment.profession_type);
      }
      continue;
    }

    summaries.set(key, {
      id: key,
      displayName: appointment.individual.display_name,
      professionTypes: [appointment.profession_type],
      relationshipType: appointment.relationship_type,
    });
  }

  return [...summaries.values()];
}

function initials(name: string | null): string {
  if (!name) {
    return "—";
  }

  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join("");
}

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
  const professionals = summarizeProfessionals(appointments);
  const internalProfessionals = professionals.filter(
    (item) => item.relationshipType === "INTERNAL",
  );
  const externalProfessionals = professionals.filter(
    (item) => item.relationshipType === "EXTERNAL",
  );

  const renderProfessional = (professional: ProfessionalSummary, internal: boolean) => {
    const displayName = professional.displayName ?? t("unnamedProfessional");
    const professionLabel = professional.professionTypes
      .map((profession) => t(`professionTypes.${profession}`))
      .join(" & ");

    return (
      <li key={professional.id} className="flex min-w-0 items-start gap-2.5">
        <span
          aria-hidden="true"
          className={
            internal
              ? "bg-brand-gold text-primary grid size-9 shrink-0 place-items-center rounded-full text-xs font-semibold shadow-sm"
              : "bg-notary/12 text-notary grid size-9 shrink-0 place-items-center rounded-full text-xs font-semibold"
          }
        >
          {initials(professional.displayName)}
        </span>

        <div className="min-w-0 flex-1">
          <p className="truncate text-xs font-semibold">{displayName}</p>
          <p className="text-muted-foreground mt-0.5 text-[11px] leading-snug">
            {internal ? professionLabel : `${professionLabel} (${t("externalShort")})`}
          </p>
          <p className="text-muted-foreground text-[11px] leading-snug">
            {internal
              ? t("internalProfessionalRole", { profession: professionLabel })
              : t("collaborationPartner")}
          </p>
        </div>
      </li>
    );
  };

  return (
    <DashboardPanel
      title={t("professionalCollaborators")}
      action={
        <Link
          href="/settings/office-practice"
          className="text-primary hover:text-primary/75 inline-flex items-center gap-1 text-xs font-medium underline-offset-4 hover:underline"
        >
          {t("viewAll")}
          <ArrowRight aria-hidden="true" className="size-3.5" />
        </Link>
      }
      isPending={userPending || query.isPending}
      isError={query.isError}
      unavailable={false}
      isEmpty={professionals.length === 0}
      emptyMessage={t("noProfessionalCollaborators")}
    >
      <div className="flex flex-col gap-3">
        <div className="grid gap-3 sm:grid-cols-2">
          <section
            aria-labelledby="dashboard-internal-professionals"
            className="border-brand-gold/25 from-brand-gold/15 via-card to-brand-gold/5 flex min-h-36 min-w-0 flex-col rounded-xl border bg-gradient-to-br p-4"
          >
            <h3
              id="dashboard-internal-professionals"
              className="text-primary mb-3 font-serif text-xs font-semibold"
            >
              {t("internalProfessionalsGroup")}
            </h3>
            {internalProfessionals.length > 0 ? (
              <ul className="flex flex-col gap-3">
                {internalProfessionals
                  .slice(0, 2)
                  .map((professional) => renderProfessional(professional, true))}
              </ul>
            ) : (
              <p className="text-muted-foreground text-xs">{t("notAvailable")}</p>
            )}
          </section>

          <section
            aria-labelledby="dashboard-external-professionals"
            className="border-notary/15 from-notary/10 via-card to-primary/5 flex min-h-36 min-w-0 flex-col rounded-xl border bg-gradient-to-br p-4"
          >
            <h3
              id="dashboard-external-professionals"
              className="text-notary mb-3 font-serif text-xs font-semibold"
            >
              {t("externalProfessionalsGroup")}
            </h3>
            {externalProfessionals.length > 0 ? (
              <ul className="flex flex-col gap-3">
                {externalProfessionals
                  .slice(0, 2)
                  .map((professional) => renderProfessional(professional, false))}
              </ul>
            ) : (
              <p className="text-muted-foreground text-xs">{t("notAvailable")}</p>
            )}
          </section>
        </div>

        <p className="text-muted-foreground text-right font-serif text-[11px] italic">
          {t("collaborationMotto")}
        </p>
      </div>
    </DashboardPanel>
  );
}
