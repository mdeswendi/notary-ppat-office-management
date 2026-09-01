"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { hasFieldError, toProjectErrorKey } from "@/features/projects/project-errors";
import { assignProjectPic, getProjectAssigneeOptions, projectQueryKeys } from "@/services/projects";
import type { Project } from "@/types/project";

/**
 * Who is in charge of this Project.
 *
 * Read-only for anybody without `projects.assign` — the current PIC is ordinary
 * Project data, and hiding it from a reader who may see the Project would be odd.
 * What the flag gates is the control, not the fact.
 *
 * **The candidate list is same-Office and active only**, which is the same rule
 * the backend enforces on the assignment itself. That restriction is not
 * cosmetic: `ASSIGNED` grants reach when `pic_user_id == actor.id` (D-088), so a
 * cross-office PIC would hand somebody reach over a Project their own scope never
 * included — without any role changing. The options endpoint therefore cannot
 * offer a candidate the assignment would refuse.
 *
 * The list is fetched only when the control is rendered, so a reader without the
 * capability never triggers the request.
 */
export function ProjectAssignmentSection({ project }: { project: Project }) {
  const t = useTranslations("projects");

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <span className="text-sm font-medium">{t("currentPicLabel")}</span>
        <span className="text-muted-foreground text-sm">
          {project.pic?.name ?? t("unassigned")}
        </span>
      </div>

      {project.can_assign ? <AssignmentForm project={project} /> : null}
    </div>
  );
}

function AssignmentForm({ project }: { project: Project }) {
  const t = useTranslations("projects");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [picUserId, setPicUserId] = useState(project.pic?.id ?? "");
  const [errorKey, setErrorKey] = useState<string | null>(null);
  const [ineligible, setIneligible] = useState(false);

  const options = useQuery({
    queryKey: projectQueryKeys.assigneeOptions(project.id),
    queryFn: () => getProjectAssigneeOptions(project.id),
    retry: false,
  });

  const mutation = useMutation({
    // An empty selection means unassign, and the backend requires the field to
    // be present — an absent key would be a 422, not a silent no-op.
    mutationFn: () =>
      assignProjectPic(project.id, { pic_user_id: picUserId === "" ? null : picUserId }),
    onSuccess: async () => {
      setErrorKey(null);
      setIneligible(false);
      await queryClient.invalidateQueries({ queryKey: projectQueryKeys.all });
    },
    onError: (error: unknown) => {
      // The backend answers one indistinguishable message for every
      // ineligibility — not found, disabled, another Office — so the endpoint is
      // not a directory probe. The interface says the same thing.
      setIneligible(hasFieldError(error, "pic_user_id"));
      setErrorKey(hasFieldError(error, "pic_user_id") ? null : toProjectErrorKey(error));
    },
  });

  if (options.isPending) {
    return <Skeleton className="h-24 w-full" />;
  }

  return (
    <form
      noValidate
      className="flex max-w-md flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault();
        setErrorKey(null);
        setIneligible(false);
        mutation.mutate();
      }}
    >
      {errorKey ? (
        <p
          role="alert"
          className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
        >
          {t(`errors.${errorKey}`)}
        </p>
      ) : null}

      <div className="flex flex-col gap-2">
        <Label htmlFor="project-pic">{t("picLabel")}</Label>
        <Select
          id="project-pic"
          value={picUserId}
          aria-invalid={ineligible ? true : undefined}
          aria-describedby={ineligible ? "project-pic-error" : undefined}
          onChange={(event) => setPicUserId(event.target.value)}
        >
          <option value="">{t("unassignOption")}</option>
          {(options.data?.users ?? []).map((user) => (
            <option key={user.id} value={user.id}>
              {user.name}
            </option>
          ))}
        </Select>

        {ineligible ? (
          <p id="project-pic-error" className="text-destructive text-sm">
            {t("picIneligible")}
          </p>
        ) : (
          <p className="text-muted-foreground text-xs">{t("picHint")}</p>
        )}
      </div>

      <div>
        <Button type="submit" size="sm" disabled={mutation.isPending}>
          {mutation.isPending ? tActions("saving") : t("assignAction")}
        </Button>
      </div>
    </form>
  );
}
