"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { ButtonLink } from "@/components/ui/button-link";
import { Card, CardHeader } from "@/components/ui/card";
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
import { ProjectDeedsSection } from "@/features/notary/project-deeds-section";
import { PpatProjectDeedsSection } from "@/features/ppat/project-deeds-section";
import { ProjectPropertiesSection } from "@/features/properties/project-properties-section";
import { ProjectAssignmentSection } from "@/features/projects/project-assignment-section";
import { EntityTaskSection } from "@/features/tasks/entity-task-section";
import { ProjectPriorityBadge, ProjectStatusBadge } from "@/features/projects/project-badges";
import { ProjectPartiesSection } from "@/features/projects/project-parties-section";
import { toProjectErrorKey } from "@/features/projects/project-errors";
import { useRouter } from "@/i18n/navigation";
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
 * **Five sections, each asking its own endpoint under its own capability.**
 * Participation (M3.4), Documents (M5.2), Tasks (M5.4) and Notarial Deeds (O-037)
 * are all reached through their own permission and Data Scope, never through the
 * Project's — so a reader who can open the Project and not one of these sees that
 * section fail honestly rather than see a fabricated empty one.
 *
 * *(This docblock previously said the page deliberately had no participation,
 * document or deed section. That was true at M3.3 and each milestone since has
 * added one; the reason it gave — an empty tab is a promise the product cannot keep,
 * D-064 — is why each waited for its routes to exist rather than being stubbed.)*
 *
 * **There is still no Matter and no workflow section**, and those are the ones
 * D-064 still applies to: a Matter is reached at its own domain root, never through
 * a Project address (D-101).
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
            <ButtonLink variant="outline" href={`/projects/${project.id}/edit`}>
              {t("editAction")}
            </ButtonLink>
          ) : null}

          {project.can_archive ? <ArchiveButton projectId={project.id} /> : null}
        </div>
      </div>

      <Card>
        <CardHeader title={t("overviewSection")} description={t("overviewDescription")} />

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
      </Card>

      {project.can_change_status ? (
        <Card>
          <CardHeader title={t("statusSection")} description={t("statusDescription")} />

          <StatusForm project={project} />
        </Card>
      ) : null}

      <Card>
        <CardHeader title={t("assignmentSection")} description={t("assignmentDescription")} />

        <ProjectAssignmentSection project={project} />
      </Card>

      {/*
        Participation (M3.4). Rendered unconditionally rather than behind a
        capability flag on the Project: the section answers to its own
        permissions, and the component asks its own endpoint — which returns 403
        for a reader who holds neither, and that is the honest state to show.
      */}
      <Card>
        <CardHeader title={tParties("section")} description={tParties("sectionDescription")} />

        <ProjectPartiesSection projectId={project.id} />
      </Card>

      {/* Documents (M5.2, D-117). A section, not a tab — the same pattern this
          page already uses for participation, and the M5 lock's own ruling.
          It answers to `documents.view` and its own endpoint, so a reader who can
          open the Project and not its documents sees the section fail honestly
          rather than see a fabricated empty one. */}
      <Card>
        <DocumentRelationSection
          filter={{ project_id: project.id }}
          uploadHref="/documents/upload"
          attachTo={{ entity_type: "project", entity_id: project.id }}
        />
      </Card>

      {/* Tasks (M5.4, D-119). A section, not a tab — the pattern this page already
          uses for participation and documents. It answers to `tasks.view` and its
          own endpoint, so a reader who can open the Project and not its tasks sees
          the section fail honestly rather than see a fabricated empty one. */}
      <Card>
        <EntityTaskSection
          filter={{ project_id: project.id }}
          createHref={`/tasks/new?project_id=${project.id}`}
        />
      </Card>

      {/* Notarial Deeds (O-037). A section, not a tab, like the four above it, and
          it filters `/notary/deeds?project_id=` rather than calling a nested route
          — the shape D-118 refused, and the shape Documents and Tasks already
          avoid on this same page.

          **The one surface that reaches grandchildren.** A Project holds Matters
          and Matters hold Deeds; this answers "what has this engagement actually
          produced?" without opening each Matter in turn. It answers to
          `notary.deeds.view` on its own endpoint, so reaching the Project confers
          nothing here (D-100) and a reader who holds one and not the other sees an
          honest failure rather than a fabricated empty section.

          PPAT deeds cannot appear: `notary_deeds` rows exist only against NOTARY
          Matters. Their own section is below. */}
      <Card>
        <ProjectDeedsSection projectId={project.id} />
      </Card>

      {/* PPAT Deeds (M7.2, D-121). The mirror of the section above, filtering
          `/ppat/deeds?project_id=` through the same correlated query.

          **Two sections rather than one merged list**, because Notary and PPAT are
          separate business domains (`CLAUDE.md` section 16) reading separate tables
          through separate capabilities. Merging them would need a reader to hold
          both to see either half honestly, and would put a "domain" column on a page
          whose two halves already answer different questions. Each section fails on
          its own if its capability is missing. */}
      <Card>
        <PpatProjectDeedsSection projectId={project.id} />
      </Card>

      {/* Which land this engagement is about (M7.3, D-121). The same `?project_id=`
          shape as the two deed sections, correlated one junction further — a Property
          has no `project_id` of its own, so the query reaches `matter_properties` to
          `matters` to `project_id`.

          It answers to `properties.view` on its own endpoint, so reaching the Project
          confers nothing here (D-100) and a reader who holds one and not the other
          sees an honest failure rather than a fabricated empty section. */}
      <Card>
        <ProjectPropertiesSection projectId={project.id} />
      </Card>
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
