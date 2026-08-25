"use client";

import { useTranslations } from "next-intl";

import { PropertiesList } from "@/features/properties/properties-list";

/**
 * Every land object the Matters in one Project concern (M7.3, D-121).
 *
 * **A section, not a tab**, like the six already on the Project page.
 *
 * **It filters the Property list rather than calling a nested route.** The obvious
 * alternative, `GET /projects/{project}/properties`, is the shape D-118 refused for
 * exactly this question: *"A second address for one question is two surfaces that must
 * be kept in step, and the first divergence between them would be a bug."* Documents,
 * Tasks and both deed families all answer this page through `?project_id=`, and so does
 * this.
 *
 * **This filter reaches two junctions deep**, which is one further than the deed
 * sections and worth naming. A Property has no `project_id` — it is office-owned
 * reference data that predates every Matter that names it — so the query correlates
 * `matter_properties` to `matters` to `project_id`. The exception is earned for the
 * reason O-037 gave the deed sections: *"what has this engagement actually produced?"*
 * is a question about the Project, and answering it by opening each Matter in turn is
 * the thing a summary exists to avoid. Here the question is *"which land is this
 * engagement about?"*, which an office asks at least as often.
 *
 * **It asks its own endpoint**, under `properties.view` with its own Data Scope.
 * Reaching a Project confers no Property reach (D-100), so a reader who can open the
 * Project and not its land sees this section fail honestly rather than see a fabricated
 * empty one.
 *
 * **No create control.** Recording a parcel is not something a Project page does: a
 * Property exists independently of any engagement, and the control belongs on the
 * Property surface.
 */
export function ProjectPropertiesSection({ projectId }: { projectId: string }) {
  const t = useTranslations("properties");

  return (
    <>
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-medium">{t("projectSectionTitle")}</h2>
        <p className="text-muted-foreground text-sm">{t("projectSectionDescription")}</p>
      </div>

      <PropertiesList
        fixedFilter={{ project_id: projectId }}
        emptyTitleKey="projectSectionEmptyTitle"
        emptyDescriptionKey="projectSectionEmptyDescription"
        showCreate={false}
      />
    </>
  );
}
