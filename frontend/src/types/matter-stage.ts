/**
 * The state of one stage in a running Matter workflow.
 *
 * Stable codes mirroring the backend enum exactly, transcribed from
 * `03_DATABASE_ERD.md` section 11. The interface translates them for display; the
 * code is what travels (CLAUDE.md section 12).
 *
 * **Only three are reachable in M4** *(D-112)*. A stage starts `PENDING`, the
 * first becomes `ACTIVE`, and moving on marks the one you left `COMPLETED`.
 * `SKIPPED` and `BLOCKED` are vocabulary the interface can render and nothing can
 * set: skipping is a decision somebody makes and moving to a later stage is not
 * that decision, and blocking needs a blocking rule no canonical document
 * defines. Both are rendered anyway, because the backend may one day return them
 * and a stepper that could not draw them would be lying about what it knows.
 */
export const MATTER_STAGE_STATUSES = [
  "PENDING",
  "ACTIVE",
  "COMPLETED",
  "SKIPPED",
  "BLOCKED",
] as const;

export type MatterStageStatus = (typeof MATTER_STAGE_STATUSES)[number];

/**
 * One stage of a running workflow.
 *
 * **The names are snapshots.** They were copied from the template when this
 * Matter's workflow started, so a template renamed since displays here as it was
 * then — the requirement of `CLAUDE.md` section 18. Nothing in the interface may
 * fetch a "current" name from anywhere else.
 *
 * `assignee` is operational information and **never a capability**: a stage
 * assignee has no Matter reach (D-100), so no control may be enabled because of
 * it.
 */
export type MatterStage = {
  id: string;
  stage_code: string;
  stage_name_id: string;
  stage_name_en: string;
  sequence_no: number;
  status: MatterStageStatus;
  started_at: string | null;
  completed_at: string | null;
  assignee: { id: string; name: string } | null;
  approved_at: string | null;
};

/**
 * One recorded transition.
 *
 * Codes rather than resolved stages, exactly as stored: resolving them through
 * live rows would let a later template edit rewrite what the record says
 * happened. A code with no matching stage is therefore normal and is displayed
 * as itself.
 *
 * `reason` is free text somebody typed. It is displayed as written and must never
 * be treated as structured data.
 */
export type MatterStageHistoryEntry = {
  id: string;
  from_stage_code: string | null;
  to_stage_code: string;
  reason: string | null;
  changed_at: string | null;
  changed_by: { id: string; name: string } | null;
};

export type MatterWorkflowRun = {
  id: string;
  workflow_version: number;
  started_at: string | null;
  completed_at: string | null;
};

export type MatterWorkflowPage = {
  data: {
    workflow: MatterWorkflowRun | null;
    current_stage: MatterStage | null;
    stages: MatterStage[];
    history: MatterStageHistoryEntry[];
  };
  meta: {
    has_workflow: boolean;
    can_change_stage: boolean;
  };
};

/** A stage this Matter may be moved to: open, and not the one already active. */
export type MatterStageOption = {
  stage_code: string;
  stage_name_id: string;
  stage_name_en: string;
  sequence_no: number;
  status: MatterStageStatus;
};

export type MatterStageOptions = {
  stages: MatterStageOption[];
};

/**
 * What a move accepts.
 *
 * The Matter and the domain come from the address. There is **no transition
 * matrix** (D-104): the backend checks that the target belongs to this Matter's
 * workflow and is open, never which stage may follow which, so the interface
 * offers every open stage rather than a "next" one.
 */
export type MatterStageMoveInput = {
  target_stage_code: string;
  reason?: string | null;
};
