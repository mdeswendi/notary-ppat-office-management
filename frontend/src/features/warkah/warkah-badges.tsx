"use client";

import { Check, Circle } from "lucide-react";
import { useTranslations } from "next-intl";

import { Badge, type BadgeTone } from "@/components/ui/badge";
import type { WarkahStatus } from "@/types/warkah";

/**
 * Warkah state and line state, as labelled badges (M7.4, D-121).
 *
 * **State must not rely on colour alone** (`CLAUDE.md` section 49): every badge carries
 * its translated text, and the tint is a secondary cue rather than the information
 * itself.
 *
 * The PPAT teal is used lightly, as section 42 requires. `COMPLETE` takes the one
 * emphasis, because a verified bundle is the state an office is working toward.
 */

const STATUS_TONE: Record<WarkahStatus, BadgeTone> = {
  INCOMPLETE: "neutral",
  UNDER_REVIEW: "ppat",
  COMPLETE: "ppatStrong",

  // Storable, reached by nothing. Rendered muted so a bundle carrying one — written
  // directly to the database, or by a milestone that answers open question eight —
  // still reads correctly.
  FINALIZED: "muted",
  ARCHIVED: "muted",
};

export function WarkahStatusBadge({ status }: { status: WarkahStatus }) {
  const t = useTranslations("warkah");

  return (
    <Badge tone={STATUS_TONE[status]} aria-label={`${t("status")}: ${t(`statuses.${status}`)}`}>
      {t(`statuses.${status}`)}
    </Badge>
  );
}

/**
 * Whether anything has been filed against a line.
 *
 * **This is what replaces the item status the ERD never defined.**
 * `ppat_warkah_items.status` has no canonical vocabulary — the M7.4 brief proposed six
 * values, and an item-status vocabulary *is* the verification rule, which is open
 * question three (O-041).
 *
 * So the badge states the fact that is observable and needs no vocabulary, and it is
 * the same fact completeness counts — the list and the percentage cannot disagree.
 *
 * The icon is decorative and the text carries the meaning; an icon alone would be the
 * shape-only signal section 49 rules out.
 */
export function WarkahItemCollectedBadge({ hasDocument }: { hasDocument: boolean }) {
  const t = useTranslations("warkah");

  if (hasDocument) {
    return (
      <Badge tone="ppat" withIcon>
        <Check aria-hidden="true" className="size-3" />
        {t("collected")}
      </Badge>
    );
  }

  return (
    <Badge withIcon>
      <Circle aria-hidden="true" className="size-3" />
      {t("notCollected")}
    </Badge>
  );
}

/**
 * The office's own requirement code, shown as it was typed.
 *
 * **Not translated, and not expanded.** `requirement_code` is stored and matched
 * against nothing: what it would match is a requirement template, and D-104 keeps those
 * unbuilt. There is no catalogue to look a code up in, so it renders verbatim — the
 * treatment `deed_type_code` and `right_type` already get.
 */
export function WarkahRequirementBadge({ code }: { code: string | null }) {
  const t = useTranslations("warkah");

  if (code === null || code === "") {
    return null;
  }

  return <Badge aria-label={`${t("requirementCode")}: ${code}`}>{code}</Badge>;
}
