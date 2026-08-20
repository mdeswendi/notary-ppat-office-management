"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Check, Circle, CircleDot, SkipForward } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

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
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { toMatterErrorKey } from "@/features/matters/matter-errors";
import {
  getMatterStageOptions,
  getMatterWorkflow,
  matterStageQueryKeys,
  moveMatterStage,
} from "@/services/matter-stages";
import type { MatterDomain } from "@/types/matter";
import type { MatterStage, MatterStageStatus } from "@/types/matter-stage";

type SectionProps = { domain: MatterDomain; matterId: string };

/**
 * Where this Matter is in its process (M4.7, D-112).
 *
 * **A Matter with no workflow is the ordinary state, not an error.** D-104 seeds
 * no templates, so until an office configures one every Matter is in exactly that
 * state — and the section says so plainly rather than showing an empty stepper or
 * an error that suggests something broke.
 *
 * **Names come from the snapshot.** Each stage displays the name its template
 * carried when this Matter started, so editing a template never changes what a
 * running Matter shows (`CLAUDE.md` section 18). Nothing here fetches a "current"
 * name from anywhere.
 *
 * **There is no next-stage button and deliberately no ordering rule.** M4 has no
 * transition matrix (D-104): the move dialog offers every open stage, forward or
 * backward, because which stage may follow which is workflow content nobody has
 * validated. Offering only "next" would be that content, invented by an
 * interface.
 *
 * **Every status renders, including the two nothing can set.** `SKIPPED` and
 * `BLOCKED` are unreachable in M4 (D-112); drawing them anyway costs two icons
 * and means the stepper never meets a status it cannot express.
 *
 * Status is never conveyed by colour alone — each step carries an icon and a
 * translated label (CLAUDE.md section 49).
 */
export function MatterWorkflowSection({ domain, matterId }: SectionProps) {
  const t = useTranslations("matterStages");
  const tActions = useTranslations("actions");

  const query = useQuery({
    queryKey: matterStageQueryKeys.all(domain, matterId),
    queryFn: () => getMatterWorkflow(domain, matterId),
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        {[0, 1, 2].map((row) => (
          <Skeleton key={row} className="h-10 w-full" />
        ))}
      </div>
    );
  }

  if (query.isError) {
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

  const page = query.data;

  if (!page?.meta.has_workflow) {
    // Stated as a configuration fact rather than an error: the office has not
    // set up a process, which is where this is blocked (D-104).
    return <p className="text-muted-foreground text-sm">{t("noWorkflow")}</p>;
  }

  const stages = page.data.stages;
  const history = page.data.history;

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-center gap-3">
        <p className="text-muted-foreground text-xs">
          {t("versionLabel", { version: page.data.workflow?.workflow_version ?? 1 })}
        </p>

        {page.data.workflow?.completed_at ? (
          <span className="border-border text-muted-foreground rounded-full border px-2 py-0.5 text-xs">
            {t("workflowCompleted")}
          </span>
        ) : null}

        {page.meta.can_change_stage && page.data.workflow?.completed_at === null ? (
          <MoveStage domain={domain} matterId={matterId} />
        ) : null}
      </div>

      {stages.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("noStages")}</p>
      ) : (
        <ol className="flex flex-col">
          {stages.map((stage, index) => (
            <StageStep key={stage.id} stage={stage} isLast={index === stages.length - 1} />
          ))}
        </ol>
      )}

      <section className="flex flex-col gap-2">
        <h4 className="text-sm font-medium">{t("historyTitle")}</h4>

        {history.length === 0 ? (
          <p className="text-muted-foreground text-sm">{t("noHistory")}</p>
        ) : (
          <ul className="divide-border divide-y text-sm">
            {history.map((entry) => (
              <li key={entry.id} className="flex flex-col gap-0.5 py-2">
                <span>
                  {entry.from_stage_code === null
                    ? t("historyStarted", { to: entry.to_stage_code })
                    : t("historyMoved", { from: entry.from_stage_code, to: entry.to_stage_code })}
                </span>
                <span className="text-muted-foreground text-xs">
                  {[entry.changed_by?.name, entry.changed_at].filter(Boolean).join(" — ")}
                </span>
                {entry.reason ? (
                  <span className="text-muted-foreground text-xs italic">{entry.reason}</span>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

/**
 * One step of the vertical stepper.
 *
 * The icon and the label carry the status together, so nothing depends on colour.
 */
function StageStep({ stage, isLast }: { stage: MatterStage; isLast: boolean }) {
  const t = useTranslations("matterStages");
  const locale = useLocale();

  const name = locale === "en" ? stage.stage_name_en : stage.stage_name_id;

  return (
    <li className="flex gap-3">
      <div className="flex flex-col items-center">
        <StageIcon status={stage.status} />
        {!isLast ? (
          <span
            aria-hidden="true"
            className={`w-px flex-1 ${
              stage.status === "SKIPPED" ? "border-border border-l border-dashed" : "bg-border"
            }`}
          />
        ) : null}
      </div>

      <div className="flex min-w-0 flex-col gap-0.5 pb-4">
        <span
          className={`text-sm ${stage.status === "ACTIVE" ? "font-medium" : ""} ${
            stage.status === "SKIPPED" ? "text-muted-foreground line-through" : ""
          }`}
        >
          {stage.sequence_no}. {name}
        </span>

        <span className="text-muted-foreground text-xs">
          {t(`statuses.${stage.status}`)}
          {stage.assignee ? ` — ${stage.assignee.name}` : ""}
        </span>
      </div>
    </li>
  );
}

function StageIcon({ status }: { status: MatterStageStatus }) {
  const t = useTranslations("matterStages");
  const label = t(`statuses.${status}`);

  const shared = "size-4 shrink-0";

  switch (status) {
    case "COMPLETED":
      return <Check aria-label={label} className={`${shared} text-primary`} />;
    case "ACTIVE":
      return <CircleDot aria-label={label} className={`${shared} text-primary`} />;
    case "BLOCKED":
      return <AlertTriangle aria-label={label} className={`${shared} text-destructive`} />;
    case "SKIPPED":
      return <SkipForward aria-label={label} className={`${shared} text-muted-foreground`} />;
    default:
      return <Circle aria-label={label} className={`${shared} text-muted-foreground`} />;
  }
}

/**
 * Move to another stage.
 *
 * The destination list comes from the backend, which returns every open stage.
 * The interface adds no ordering of its own — see the section docblock.
 */
function MoveStage({ domain, matterId }: SectionProps) {
  const t = useTranslations("matterStages");
  const tActions = useTranslations("actions");
  const locale = useLocale();
  const queryClient = useQueryClient();

  const [open, setOpen] = useState(false);
  const [target, setTarget] = useState("");
  const [reason, setReason] = useState("");
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const options = useQuery({
    queryKey: matterStageQueryKeys.options(domain, matterId),
    queryFn: () => getMatterStageOptions(domain, matterId),
    enabled: open,
    retry: false,
  });

  const mutation = useMutation({
    mutationFn: () =>
      moveMatterStage(domain, matterId, {
        target_stage_code: target,
        reason: reason === "" ? null : reason,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: matterStageQueryKeys.all(domain, matterId),
      });
      await queryClient.invalidateQueries({
        queryKey: matterStageQueryKeys.options(domain, matterId),
      });
      setOpen(false);
      setTarget("");
      setReason("");
      setErrorKey(null);
    },
    onError: (error: unknown) => setErrorKey(toMatterErrorKey(error)),
  });

  const stages = options.data?.stages ?? [];

  return (
    <>
      <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
        {t("moveAction")}
      </Button>

      <Dialog open={open} onOpenChange={(next) => setOpen(next)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("moveTitle")}</DialogTitle>
            <DialogDescription>{t("moveDescription")}</DialogDescription>
          </DialogHeader>

          {errorKey ? (
            <p
              role="alert"
              className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
            >
              {t(`errors.${errorKey}`)}
            </p>
          ) : null}

          <form
            noValidate
            className="flex flex-col gap-4"
            onSubmit={(event) => {
              event.preventDefault();
              setErrorKey(null);
              mutation.mutate();
            }}
          >
            <div className="flex flex-col gap-2">
              <Label htmlFor="matter-stage-target">{t("targetLabel")}</Label>
              <select
                id="matter-stage-target"
                className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                value={target}
                onChange={(event) => setTarget(event.target.value)}
              >
                <option value="">{t("selectStage")}</option>
                {stages.map((stage) => (
                  <option key={stage.stage_code} value={stage.stage_code}>
                    {stage.sequence_no}.{" "}
                    {locale === "en" ? stage.stage_name_en : stage.stage_name_id}
                  </option>
                ))}
              </select>

              {!options.isPending && stages.length === 0 ? (
                <p className="text-muted-foreground text-xs">{t("noTargets")}</p>
              ) : (
                <p className="text-muted-foreground text-xs">{t("targetHint")}</p>
              )}
            </div>

            <div className="flex flex-col gap-2">
              <Label htmlFor="matter-stage-reason">{t("reasonLabel")}</Label>
              <Input
                id="matter-stage-reason"
                value={reason}
                maxLength={255}
                onChange={(event) => setReason(event.target.value)}
              />
              {/* Recorded permanently in append-only history and readable by
                  everyone who may read the Matter (D-104, D-105). */}
              <p className="text-muted-foreground text-xs">{t("reasonHint")}</p>
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                {tActions("cancel")}
              </Button>
              <Button type="submit" disabled={target === "" || mutation.isPending}>
                {mutation.isPending ? tActions("saving") : t("moveConfirm")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
}
