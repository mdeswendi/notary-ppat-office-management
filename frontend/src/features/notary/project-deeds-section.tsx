"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { DeedsList } from "@/features/notary/deeds-list";
import { getMatters, matterQueryKeys } from "@/services/matters";
import type { MatterListQuery } from "@/types/matter";

/**
 * Every Notarial Deed produced under one Project (O-037).
 *
 * **A section, not a tab**, like the four already on the Project page — the ruling
 * M5.2 made and every milestone since has followed: the repository has no `Tabs`
 * primitive.
 *
 * **It filters the deed list rather than calling a nested route.** The obvious
 * alternative, `GET /projects/{project}/notary-deeds`, is the shape D-118 refused
 * for exactly this question: *"A second address for one question is two surfaces
 * that must be kept in step, and the first divergence between them would be a
 * bug."* Documents and Tasks both answer this page through `?project_id=`, and so
 * does this.
 *
 * **It shows grandchildren, and that is the point rather than an oversight.** A
 * Project holds Matters and Matters hold Deeds, so this is the one surface that
 * reaches two levels down — which is why O-037 recorded it as a question rather
 * than a plain miss. It earns the exception because *"what has this engagement
 * actually produced?"* is a question about the Project, and answering it by opening
 * each Matter in turn is the thing a summary exists to avoid.
 *
 * Because rows span several Matters, the list is given `matterOptions`: it grows a
 * Matter column and a Matter dropdown that the single-Matter view deliberately does
 * without.
 *
 * **It asks its own endpoint**, under `notary.deeds.view` with its own Data Scope.
 * Reaching a Project confers no deed reach (D-100), so a reader who can open the
 * Project and not its deeds sees this section fail honestly rather than see a
 * fabricated empty one.
 *
 * **No PPAT deeds appear, and none can.** `notary_deeds` rows exist only against
 * NOTARY Matters — the Policy refuses a PPAT parent — so a Project running both
 * domains shows only its Notary output. PPAT deeds are a different table in M7.
 */
export function ProjectDeedsSection({ projectId }: { projectId: string }) {
  const t = useTranslations("notary");

  // The Project's Notary Matters, for the filter. Scoped by
  // `notary.matters.view` on its own endpoint, so it can never offer a Matter the
  // caller may not reach.
  const matterQuery: MatterListQuery = {
    page: 1,
    per_page: 100,
    search: "",
    status: "",
    priority: "",
    project_id: projectId,
  };

  const matters = useQuery({
    queryKey: matterQueryKeys.list("NOTARY", matterQuery),
    queryFn: () => getMatters("NOTARY", matterQuery),
  });

  const options = (matters.data?.data ?? []).map((matter) => ({
    id: matter.id,
    matter_number: matter.matter_number,
    title: matter.title,
  }));

  return (
    <>
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-medium">{t("projectDeeds")}</h2>
        <p className="text-muted-foreground text-sm">{t("projectDeedsDescription")}</p>
      </div>

      <DeedsList
        fixedFilter={{ project_id: projectId }}
        emptyTitleKey="projectDeedsEmptyTitle"
        emptyDescriptionKey="projectDeedsEmptyDescription"
        showCreate={false}
        matterOptions={options}
      />
    </>
  );
}
