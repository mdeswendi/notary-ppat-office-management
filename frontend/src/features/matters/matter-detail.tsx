"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Pencil } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  MatterDomainBadge,
  MatterPriorityBadge,
  MatterStatusBadge,
} from "@/features/matters/matter-badges";
import { matterBasePath } from "@/features/matters/matter-domain";
import { toMatterErrorKey } from "@/features/matters/matter-errors";
import { Link } from "@/i18n/navigation";
import {
  assignMatterPic,
  cancelMatter,
  completeMatter,
  getMatter,
  getMatterAssigneeOptions,
  matterQueryKeys,
} from "@/services/matters";
import type { MatterDomain } from "@/types/matter";

/**
 * One Matter, and the acts its capabilities allow.
 *
 * **Every control is gated on a backend-computed flag**, not on a permission
 * string the browser assembled: `can_update`, `can_assign`, `can_complete` and
 * `can_cancel` come from the real Policy, so the interface asks exactly the
 * question the server will ask. They decide what is *offered*; each endpoint
 * authorizes again, and a client that lies to itself about them gains nothing.
 *
 * **There is no status control**, and its absence is the honest reflection of the
 * canonical registry rather than an omission. Matter has `complete` and `cancel`
 * capabilities and **no `change_status`** (D-109), so `IN_PROGRESS`, `WAITING`,
 * `ON_HOLD` and `ARCHIVED` cannot be set by anybody — offering a dropdown that
 * silently failed for four of its seven options would be worse than offering
 * none.
 *
 * **No stage control either.** `*.matters.change_stage` is registered and badged
 * deferred because no workflow exists to move until M4.7 (D-104). A screen that
 * showed a stepper here would be describing something the product does not have.
 *
 * Participation is M4.5 and appears nowhere on this page.
 */
export function MatterDetail({ domain, matterId }: { domain: MatterDomain; matterId: string }) {
  const t = useTranslations("matters");
  const tActions = useTranslations("actions");
  const locale = useLocale();
  const queryClient = useQueryClient();

  const [pic, setPic] = useState<string>("");
  const [picTouched, setPicTouched] = useState(false);

  const query = useQuery({
    queryKey: matterQueryKeys.detail(domain, matterId),
    queryFn: () => getMatter(domain, matterId),
  });

  const matter = query.data;

  const assignees = useQuery({
    queryKey: matterQueryKeys.assigneeOptions(domain, matterId),
    queryFn: () => getMatterAssigneeOptions(domain, matterId),
    enabled: matter?.can_assign === true,
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: matterQueryKeys.all(domain) });
  };

  const assignment = useMutation({
    mutationFn: (value: string) =>
      assignMatterPic(domain, matterId, { pic_user_id: value === "" ? null : value }),
    onSuccess: invalidate,
  });

  const complete = useMutation({
    mutationFn: () => completeMatter(domain, matterId),
    onSuccess: invalidate,
  });

  const cancel = useMutation({
    mutationFn: () => cancelMatter(domain, matterId),
    onSuccess: invalidate,
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-3" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (query.isError || !matter) {
    return (
      <BaseErrorState
        title={t("errorTitle")}
        description={t(`errors.${toMatterErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  const picValue = picTouched ? pic : (matter.pic?.id ?? "");

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex flex-col gap-2">
          <span className="text-muted-foreground font-mono text-xs">{matter.matter_number}</span>
          <h2 className="text-xl font-semibold">{matter.title}</h2>
          <div className="flex flex-wrap items-center gap-2">
            <MatterDomainBadge domain={matter.domain} />
            <MatterStatusBadge status={matter.status} />
            <MatterPriorityBadge priority={matter.priority} />
          </div>
        </div>

        {matter.can_update ? (
          <Button
            variant="outline"
            className="gap-2"
            render={<Link href={`${matterBasePath(domain)}/${matter.id}/edit`} />}
          >
            <Pencil aria-hidden="true" />
            {tActions("edit")}
          </Button>
        ) : null}
      </header>

      <dl className="grid gap-4 sm:grid-cols-2">
        <Detail label={t("projectLabel")}>
          {matter.project ? `${matter.project.project_number} — ${matter.project.title}` : "—"}
        </Detail>
        <Detail label={t("serviceTypeLabel")}>
          {matter.service_type
            ? `${locale === "en" ? matter.service_type.name_en : matter.service_type.name_id}${
                matter.service_type.is_active ? "" : ` (${t("serviceTypeRetired")})`
              }`
            : t("noServiceType")}
        </Detail>
        <Detail label={t("officeLabel")}>{matter.office?.name ?? "—"}</Detail>
        <Detail label={t("picLabel")}>{matter.pic?.name ?? t("unassigned")}</Detail>
        <Detail label={t("openedAtLabel")}>{matter.opened_at ?? "—"}</Detail>
        <Detail label={t("targetCompletionLabel")}>{matter.target_completion_date ?? "—"}</Detail>
        <Detail label={t("completedAtLabel")}>{matter.completed_at ?? "—"}</Detail>
      </dl>

      {matter.notes ? (
        <section className="flex flex-col gap-2">
          <h3 className="text-sm font-medium">{t("notesLabel")}</h3>
          <p className="text-muted-foreground text-sm whitespace-pre-line">{matter.notes}</p>
        </section>
      ) : null}

      {matter.can_assign ? (
        <section className="border-border flex flex-col gap-3 rounded-lg border p-4">
          <h3 className="text-sm font-medium">{t("assignmentTitle")}</h3>
          <p className="text-muted-foreground text-xs">{t("assignmentHint")}</p>

          <div className="flex flex-wrap items-end gap-3">
            <div className="flex min-w-56 flex-col gap-2">
              <Label htmlFor="matter-pic">{t("picLabel")}</Label>
              <select
                id="matter-pic"
                className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                value={picValue}
                onChange={(event) => {
                  setPicTouched(true);
                  setPic(event.target.value);
                }}
              >
                <option value="">{t("unassigned")}</option>
                {(assignees.data ?? []).map((user) => (
                  <option key={user.id} value={user.id}>
                    {user.name}
                  </option>
                ))}
              </select>
            </div>

            <Button
              variant="outline"
              disabled={assignment.isPending}
              onClick={() => assignment.mutate(picValue)}
            >
              {assignment.isPending ? tActions("saving") : tActions("save")}
            </Button>
          </div>

          {assignment.isError ? (
            <p role="alert" className="text-destructive text-sm">
              {t(`errors.${toMatterErrorKey(assignment.error)}`)}
            </p>
          ) : null}
        </section>
      ) : null}

      {matter.can_complete || matter.can_cancel ? (
        <section className="border-border flex flex-col gap-3 rounded-lg border p-4">
          <h3 className="text-sm font-medium">{t("lifecycleTitle")}</h3>
          <p className="text-muted-foreground text-xs">{t("lifecycleHint")}</p>

          <div className="flex flex-wrap gap-2">
            {matter.can_complete ? (
              <Button
                variant="outline"
                disabled={complete.isPending}
                onClick={() => complete.mutate()}
              >
                {complete.isPending ? tActions("saving") : t("completeAction")}
              </Button>
            ) : null}

            {matter.can_cancel ? (
              <Button variant="outline" disabled={cancel.isPending} onClick={() => cancel.mutate()}>
                {cancel.isPending ? tActions("saving") : t("cancelAction")}
              </Button>
            ) : null}
          </div>

          {complete.isError || cancel.isError ? (
            <p role="alert" className="text-destructive text-sm">
              {t(`errors.${toMatterErrorKey(complete.error ?? cancel.error)}`)}
            </p>
          ) : null}
        </section>
      ) : null}
    </div>
  );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-sm font-medium">{label}</dt>
      <dd className="text-muted-foreground text-sm">{children}</dd>
    </div>
  );
}
