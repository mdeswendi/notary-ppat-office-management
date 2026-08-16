"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { toProjectErrorKey } from "@/features/projects/project-errors";
import { ProjectForm } from "@/features/projects/project-form";
import { getProject, projectQueryKeys } from "@/services/projects";

/**
 * Loads the Project, then hands it to the shared form.
 *
 * Mirrors the Individual and Company edit surfaces: the form itself is agnostic
 * about how the record arrived, so create and edit cannot drift apart in their
 * field list — which is what keeps the prohibited fields prohibited in both.
 */
export function ProjectEditForm({ projectId }: { projectId: string }) {
  const t = useTranslations("projects");
  const tActions = useTranslations("actions");

  const query = useQuery({
    queryKey: projectQueryKeys.detail(projectId),
    queryFn: () => getProject(projectId),
  });

  if (query.isPending) {
    return <Skeleton className="h-96 w-full max-w-2xl" />;
  }

  if (query.isError || !query.data) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toProjectErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  return <ProjectForm project={query.data} />;
}
