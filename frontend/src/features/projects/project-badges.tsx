"use client";

import { useTranslations } from "next-intl";

import type { ProjectPriority, ProjectStatus } from "@/types/project";

/**
 * Status and priority, rendered as labelled badges.
 *
 * **Status must not rely on colour alone** (CLAUDE.md section 49): every badge
 * carries its translated text, and the subtle tint is a secondary cue rather
 * than the information itself. A reader who cannot distinguish the tints still
 * reads the status.
 *
 * The tints stay muted on purpose. This is a professional office system, not a
 * dashboard — CLAUDE.md section 39 rules out the traffic-light palette that a
 * status chip usually attracts, and a Project being `CANCELLED` is an ordinary
 * operational fact rather than an error.
 */

const STATUS_TINT: Record<ProjectStatus, string> = {
  OPEN: "border-border text-foreground",
  IN_PROGRESS: "border-primary/40 text-primary",
  WAITING: "border-border text-muted-foreground",
  ON_HOLD: "border-border text-muted-foreground",
  COMPLETED: "border-primary/30 text-primary",
  CANCELLED: "border-border text-muted-foreground",
  ARCHIVED: "border-border text-muted-foreground",
};

export function ProjectStatusBadge({ status }: { status: ProjectStatus | null }) {
  const t = useTranslations("projects");

  if (status === null) {
    return <span className="text-muted-foreground text-sm">—</span>;
  }

  return (
    <span
      className={`rounded-full border px-2 py-0.5 text-xs ${STATUS_TINT[status]}`}
      // The status is already the text content; this only names what the text is.
      aria-label={`${t("statusLabel")}: ${t(`statuses.${status}`)}`}
    >
      {t(`statuses.${status}`)}
    </span>
  );
}

export function ProjectPriorityBadge({ priority }: { priority: ProjectPriority | null }) {
  const t = useTranslations("projects");

  if (priority === null) {
    return <span className="text-muted-foreground text-sm">—</span>;
  }

  return (
    <span className="border-border text-muted-foreground rounded-full border px-2 py-0.5 text-xs">
      {t(`priorities.${priority}`)}
    </span>
  );
}
