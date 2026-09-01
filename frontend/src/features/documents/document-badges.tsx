"use client";

import { Lock } from "lucide-react";
import { useTranslations } from "next-intl";

import { Badge, type BadgeTone } from "@/components/ui/badge";
import type { DocumentStatus } from "@/types/document";

/**
 * Status and sensitivity, rendered as labelled badges.
 *
 * **Status must not rely on colour alone** (`CLAUDE.md` section 49): every badge
 * carries its translated text, and the subtle tint is a secondary cue rather than
 * the information itself. A reader who cannot distinguish the tints still reads
 * the status.
 *
 * The tints stay muted on purpose. This is a professional office system, not a
 * dashboard — section 39 rules out the traffic-light palette a status chip usually
 * attracts, and a document being `VOID` is an ordinary operational fact rather
 * than an error.
 *
 * **Three of the seven statuses cannot be reached in M5.2** (D-117): `UNDER_REVIEW`,
 * `FINAL` and `VOID`, plus `DRAFT`, which nothing produces now that upload creates
 * `RECEIVED`. They are still rendered here, because a status filter can select on
 * them and a future milestone may make them settable; what the interface does not
 * do is offer a control that claims to set one.
 */

const STATUS_TONE: Record<DocumentStatus, BadgeTone> = {
  DRAFT: "muted",
  RECEIVED: "neutral",
  UNDER_REVIEW: "primary",
  VERIFIED: "primarySubtle",
  FINAL: "primarySubtle",
  ARCHIVED: "muted",
  VOID: "muted",
};

export function DocumentStatusBadge({ status }: { status: DocumentStatus }) {
  const t = useTranslations("documents");

  return (
    <Badge
      tone={STATUS_TONE[status]}
      aria-label={`${t("statusLabel")}: ${t(`statuses.${status}`)}`}
    >
      {t(`statuses.${status}`)}
    </Badge>
  );
}

/**
 * The sensitivity marker.
 *
 * Renders nothing for an ordinary document, so the badge means something when it
 * appears rather than being a field that is always there. The icon is decorative
 * and the text carries the meaning — an icon alone would be exactly the
 * colour-and-shape-only signal section 49 rules out.
 */
export function DocumentSensitiveBadge({ isSensitive }: { isSensitive: boolean }) {
  const t = useTranslations("documents");

  if (!isSensitive) {
    return null;
  }

  return (
    <Badge withIcon>
      <Lock aria-hidden="true" className="size-3" />
      {t("sensitive")}
    </Badge>
  );
}
