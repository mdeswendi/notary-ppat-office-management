"use client";

import { Check, Circle } from "lucide-react";
import { useTranslations } from "next-intl";

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

const STATUS_TINT: Record<WarkahStatus, string> = {
  INCOMPLETE: "border-border text-foreground",
  UNDER_REVIEW: "border-ppat/40 text-ppat",
  COMPLETE: "border-ppat bg-ppat/5 text-ppat",

  // Storable, reached by nothing. Rendered muted so a bundle carrying one — written
  // directly to the database, or by a milestone that answers open question eight —
  // still reads correctly.
  FINALIZED: "border-border text-muted-foreground",
  ARCHIVED: "border-border text-muted-foreground",
};

export function WarkahStatusBadge({ status }: { status: WarkahStatus }) {
  const t = useTranslations("warkah");

  return (
    <span
      className={`rounded-full border px-2 py-0.5 text-xs ${STATUS_TINT[status]}`}
      aria-label={`${t("status")}: ${t(`statuses.${status}`)}`}
    >
      {t(`statuses.${status}`)}
    </span>
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
      <span className="border-ppat/40 text-ppat inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs">
        <Check aria-hidden="true" className="size-3" />
        {t("collected")}
      </span>
    );
  }

  return (
    <span className="border-border text-muted-foreground inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs">
      <Circle aria-hidden="true" className="size-3" />
      {t("notCollected")}
    </span>
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

  return (
    <span
      className="border-border text-muted-foreground rounded-full border px-2 py-0.5 text-xs"
      aria-label={`${t("requirementCode")}: ${code}`}
    >
      {code}
    </span>
  );
}
