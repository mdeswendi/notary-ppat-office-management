"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { DocumentRelationSection } from "@/features/documents/document-relation-section";
import { ProjectAssignmentSection } from "@/features/projects/project-assignment-section";
import { ProjectPriorityBadge, ProjectStatusBadge } from "@/features/projects/project-badges";
import { ProjectPartiesSection } from "@/features/projects/project-parties-section";
import { toProjectErrorKey } from "@/features/projects/project-errors";
import { Link, useRouter } from "@/i18n/navigation";
import {
  archiveProject,
  changeProjectStatus,
  getProject,
  projectQueryKeys,
} from "@/services/projects";
import { PROJECT_STATUSES, type Project, type ProjectStatus } from "@/types/project";

/**
 * One Project.
 *
 * **Every action is gated on a backend-computed flag**, not on a permission code
 * the browser guessed at. `can_update`, `can_assign`, `can_change_status` and
 * `can_archive` come from the real Policy with Data Scope applied, so the
 * interface offers exactly what the server would accept. They remain
 * presentation only — each endpoint authorizes again.
 *
 * There is deliberately no participants, Matter, workflow, document, or deed
 * section: `project_parties` is M3.4 and the rest are M4 and later. An empty tab
 * is a promise the product cannot keep (D-064).
 *
 * The internal reference is displayed and never editable — system-generated,
 * immutable, and unique only within its Office (D-096).
 */
export function ProjectDetail({ projectId }: { projectId: string }) {
  const t = useTranslations("projects");
  const tActions = useTranslations("actions");
  const tParties = useTranslations("projectParties");

  const query = useQuery({
    queryKey: projectQueryKeys.detail(projectId),
    queryFn: () => getProject(projectId),
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-3" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        {[0, 1].map((row) => (
          <Skeleton key={row} className="h-40 w-full" />
        ))}
      </div>
    );
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

  const project = query.data;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <span className="text-muted-foreground font-mono text-xs">{project.project_number}</span>
          <h1 className="text-2xl font-semibold tracking-tight">{project.title}</h1>
          <p className="text-muted-foreground text-sm">
            {project.office ? `${project.office.code} — ${project.office.name}` : "—"}
          </p>
        </div>

        <div className="flex flex-wrap gap-2">
          {project.can_update ? (
            <Button variant="outline" render={<Link href={`/projects/${project.id}/edit`} />}>
              {t("editAction")}
            </Button>
          ) : null}

          {project.can_archive ? <ArchiveButton projectId={project.id} /> : null}
        </div>
      </div>

      <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
        <div className="flex flex-col gap-1">
          <h2 className="text-base font-medium">{t("overviewSection")}</h2>
          <p className="text-muted-foreground text-sm">{t("overviewDescription")}</p>
        </div>

        <dl className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1">
            <dt className="text-sm font-medium">{t("statusLabel")}</dt>
            <dd>
              <ProjectStatusBadge status={project.status} />
            </dd>
          </div>
          <div className="flex flex-col gap-1">
            <dt className="text-sm font-medium">{t("priorityLabel")}</dt>
            <dd>
              <ProjectPriorityBadge priority={project.priority} />
            </dd>
          </div>
          <Detail label={t("openedAtLabel")} value={project.opened_at} />
          <Detail label={t("targetCompletionLabel")} value={project.target_completion_date} />
          <Detail label={t("descriptionLabel")} value={project.description} />
        </dl>
      </section>

      {project.can_change_status ? (
        <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
          <div className="flex flex-col gap-1">
            <h2 className="text-base font-medium">{t("statusSection")}</h2>
            <p className="text-muted-foreground text-sm">{t("statusDescription")}</p>
          </div>

          <StatusForm project={project} />
        </section>
      ) : null}

      <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
        <div className="flex flex-col gap-1">
          <h2 className="text-base font-medium">{t("assignmentSection")}</h2>
          <p className="text-muted-foreground text-sm">{t("assignmentDescription")}</p>
        </div>

        <ProjectAssignmentSection project={project} />
      </section>

      {/*
        Participation (M3.4). Rendered unconditionally rather than behind a
        capability flag on the Project: the section answers to its own
        permissions, and the component asks its own endpoint — which returns 403
        for a reader who holds neither, and that is the honest state to show.
      */}
      <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
        <div className="flex flex-col gap-1">
          <h2 className="text-base font-medium">{tParties("section")}</h2>
          <p className="text-muted-foreground text-sm">{tParties("sectionDescription")}</p>
        </div>

        <ProjectPartiesSection projectId={project.id} />
      </section>

      {/* Documents (M5.2, D-117). A section, not a tab — the same pattern this
          page already uses for participation, and the M5 lock's own ruling.
          It answers to `documents.view` and its own endpoint, so a reader who can
          open the Project and not its documents sees the section fail honestly
          rather than see a fabricated empty one. */}
      <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
        <DocumentRelationSection
          filter={{ project_id: project.id }}
          uploadHref="/documents/upload"
        />
      </section>
    </div>
  );
}

function Detail({ label, value }: { label: string; value: string | null }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-sm font-medium">{label}</dt>
      <dd className="text-muted-foreground text-sm">{value ?? "—"}</dd>
    </div>
  );
}

/**
 * Changing the business status.
 *
 * **Every canonical status is offered.** There is no transition matrix — no
 * canonical document defines which status may follow which, so the interface
 * hides nothing and the backend authorizes *who* may change status rather than
 * *which* change is legal (D-091). A dropdown that filtered options would be
 * inventing a rule.
 *
 * The wording says plainly that this is not archiving: business `ARCHIVED` and a
 * filed-away record are different states with similar names (D-093).
 */
function StatusForm({ project }: { project: Project }) {
  const t = useTranslations("projects");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [status, setStatus] = useState<ProjectStatus>(project.status ?? "OPEN");
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => changeProjectStatus(project.id, { status }),
    onSuccess: async () => {
      setErrorKey(null);
      await queryClient.invalidateQueries({ queryKey: projectQueryKeys.all });
    },
    onError: (error: unknown) => setErrorKey(toProjectErrorKey(error)),
  });

  return (
    <form
      noValidate
      className="flex max-w-md flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault();
        setErrorKey(null);
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
        <Label htmlFor="project-status">{t("statusLabel")}</Label>
        <select
          id="project-status"
          className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
          value={status}
          onChange={(event) => setStatus(event.target.value as ProjectStatus)}
        >
          {PROJECT_STATUSES.map((code) => (
            <option key={code} value={code}>
              {t(`statuses.${code}`)}
            </option>
          ))}
        </select>
        <p className="text-muted-foreground text-xs">{t("archivedStatusHint")}</p>
      </div>

      <div>
        <Button type="submit" size="sm" disabled={mutation.isPending || status === project.status}>
          {mutation.isPending ? tActions("saving") : t("changeStatusAction")}
        </Button>
      </div>
    </form>
  );
}

/**
 * Archiving, behind a confirmation.
 *
 * The wording describes the operational consequence and says the record is
 * retained rather than deleted — because it is. Archiving keeps the reference,
 * the status, the Office, and the PIC, and a holder of `projects.restore` can
 * put it back (D-093).
 */
function ArchiveButton({ projectId }: { projectId: string }) {
  const t = useTranslations("projects");
  const tActions = useTranslations("actions");
  const router = useRouter();
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => archiveProject(projectId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: projectQueryKeys.all });
      setOpen(false);
      router.push("/projects");
    },
    onError: (error: unknown) => setErrorKey(toProjectErrorKey(error)),
  });

  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        {t("archiveAction")}
      </Button>

      <Dialog open={open} onOpenChange={(next) => (next ? undefined : setOpen(false))}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("archiveTitle")}</DialogTitle>
            <DialogDescription>{t("archiveDescription")}</DialogDescription>
          </DialogHeader>

          {errorKey ? (
            <p
              role="alert"
              className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
            >
              {t(`errors.${errorKey}`)}
            </p>
          ) : null}

          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              {tActions("cancel")}
            </Button>
            <Button disabled={mutation.isPending} onClick={() => mutation.mutate()}>
              {mutation.isPending ? tActions("saving") : t("archiveConfirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
