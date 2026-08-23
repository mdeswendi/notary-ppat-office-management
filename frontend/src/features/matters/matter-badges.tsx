"use client";

import { useTranslations } from "next-intl";

import type { MatterDomain, MatterStatus } from "@/types/matter";
import type { ProjectPriority } from "@/types/project";

/**
 * Status, priority, and domain, rendered as labelled badges.
 *
 * **Status must not rely on colour alone** (CLAUDE.md section 49): every badge
 * carries its translated text, and the subtle tint is a secondary cue rather than
 * the information itself. A reader who cannot distinguish the tints still reads
 * the status.
 *
 * The tints stay muted on purpose. This is a professional office system, not a
 * dashboard — CLAUDE.md section 39 rules out the traffic-light palette a status
 * chip usually attracts, and a Matter being `CANCELLED` is an ordinary
 * operational fact rather than an error.
 *
 * **Four of the seven statuses cannot be reached in M4** (D-109) — Matter has
 * `complete` and `cancel` but no `change_status` capability. They are still
 * rendered here, because a status filter can select on them and a future
 * milestone may make them settable; what the interface does not do is offer a
 * control that claims to set one.
 */

const STATUS_TINT: Record<MatterStatus, string> = {
  OPEN: "border-border text-foreground",
  IN_PROGRESS: "border-primary/40 text-primary",
  WAITING: "border-border text-muted-foreground",
  ON_HOLD: "border-border text-muted-foreground",
  COMPLETED: "border-primary/30 text-primary",
  CANCELLED: "border-border text-muted-foreground",
  ARCHIVED: "border-border text-muted-foreground",
};

export function MatterStatusBadge({ status }: { status: MatterStatus | null }) {
  const t = useTranslations("matters");

  if (status === null) {
    return <span className="text-muted-foreground text-sm">—</span>;
  }

  return (
    <span
      className={`rounded-full border px-2 py-0.5 text-xs ${STATUS_TINT[status]}`}
      aria-label={`${t("statusLabel")}: ${t(`statuses.${status}`)}`}
    >
      {t(`statuses.${status}`)}
    </span>
  );
}

export function MatterPriorityBadge({ priority }: { priority: ProjectPriority | null }) {
  const t = useTranslations("matters");

  if (priority === null) {
    return <span className="text-muted-foreground text-sm">—</span>;
  }

  return (
    <span className="border-border text-muted-foreground rounded-full border px-2 py-0.5 text-xs">
      {t(`priorities.${priority}`)}
    </span>
  );
}

/**
 * The domain badge.
 *
 * Each surface already lives under its own address, so this appears only where
 * naming the domain adds something — a detail header. The accent is used lightly
 * (CLAUDE.md section 42): a badge, never a whole screen tinted by domain.
 */
export function MatterDomainBadge({ domain }: { domain: MatterDomain }) {
  const t = useTranslations("matters");

  return (
    <span
      className={`rounded-full border px-2 py-0.5 text-xs ${
        domain === "NOTARY" ? "border-primary/40 text-primary" : "border-border text-foreground"
      }`}
      aria-label={`${t("domainLabel")}: ${t(`domains.${domain}`)}`}
    >
      {t(`domains.${domain}`)}
    </span>
  );
}
