"use client";

import { useTranslations } from "next-intl";

import { Badge, type BadgeTone } from "@/components/ui/badge";
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
 *
 * The map names a tone rather than a class list, so what it encodes — in flight
 * versus settled versus neither — is legible without decoding opacities.
 */

const STATUS_TONE: Record<ProjectStatus, BadgeTone> = {
  OPEN: "neutral",
  IN_PROGRESS: "primary",
  WAITING: "muted",
  ON_HOLD: "muted",
  COMPLETED: "primarySubtle",
  CANCELLED: "muted",
  ARCHIVED: "muted",
};

export function ProjectStatusBadge({ status }: { status: ProjectStatus | null }) {
  const t = useTranslations("projects");

  if (status === null) {
    return <span className="text-muted-foreground text-sm">—</span>;
  }

  return (
    <Badge
      tone={STATUS_TONE[status]}
      // The status is already the text content; this only names what the text is.
      aria-label={`${t("statusLabel")}: ${t(`statuses.${status}`)}`}
    >
      {t(`statuses.${status}`)}
    </Badge>
  );
}

export function ProjectPriorityBadge({ priority }: { priority: ProjectPriority | null }) {
  const t = useTranslations("projects");

  if (priority === null) {
    return <span className="text-muted-foreground text-sm">—</span>;
  }

  return <Badge>{t(`priorities.${priority}`)}</Badge>;
}
