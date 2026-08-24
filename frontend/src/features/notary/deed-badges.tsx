"use client";

import { Lock } from "lucide-react";
import { useTranslations } from "next-intl";

import type { NotaryDeedStatus } from "@/types/notary";

/**
 * Deed status and type, rendered as labelled badges (M6.2, D-120).
 *
 * **Status must not rely on colour alone** (`CLAUDE.md` section 49): every badge
 * carries its translated text, and the tint is a secondary cue rather than the
 * information itself. A reader who cannot distinguish the tints still reads the
 * status.
 *
 * The tints stay muted on purpose. This is a professional office system, not a
 * dashboard — section 39 rules out the traffic-light palette a status chip usually
 * attracts. `FINALIZED` gets the one emphasis, because a finalized deed is a legal
 * record that can no longer be edited and that is worth seeing at a glance.
 *
 * **`VOID` and `SUPERSEDED` are rendered and never offered.** No code path produces
 * them — the correction mechanisms that would are an open domain question (D-120) —
 * but a deed written directly to the database could carry one, and a badge that
 * refused to render a canonical status would show a blank where a legal state
 * belongs. What the interface does not do is offer a control that claims to set one.
 */

const STATUS_TINT: Record<NotaryDeedStatus, string> = {
  DRAFT: "border-border text-foreground",
  UNDER_REVIEW: "border-primary/40 text-primary",
  APPROVED: "border-primary/40 text-primary",
  FINALIZED: "border-primary bg-primary/5 text-primary",
  VOID: "border-border text-muted-foreground",
  SUPERSEDED: "border-border text-muted-foreground",
};

export function NotaryDeedStatusBadge({ status }: { status: NotaryDeedStatus }) {
  const t = useTranslations("notary");

  return (
    <span
      className={`rounded-full border px-2 py-0.5 text-xs ${STATUS_TINT[status]}`}
      aria-label={`${t("status")}: ${t(`deedStatuses.${status}`)}`}
    >
      {t(`deedStatuses.${status}`)}
    </span>
  );
}

/**
 * The deed type code, shown as the office typed it.
 *
 * **Not translated, and not expanded into a name.** `deed_type_code` is opaque:
 * `03_DATABASE_ERD.md` gives it no vocabulary, M6 seeds no catalogue, and the
 * examples elsewhere in the canonical set are prose. Rendering `AJB` as
 * "Akta Jual Beli" here would require a mapping this milestone has no authority to
 * write — and inventing legal translations is exactly what `CLAUDE.md` section 9
 * forbids. The office's own code is shown verbatim.
 */
export function NotaryDeedTypeBadge({ code }: { code: string | null }) {
  const t = useTranslations("notary");

  if (code === null || code === "") {
    return <span className="text-muted-foreground text-sm">—</span>;
  }

  return (
    <span
      className="border-border text-muted-foreground rounded-full border px-2 py-0.5 text-xs"
      aria-label={`${t("deedType")}: ${code}`}
    >
      {code}
    </span>
  );
}

/**
 * The read-only marker.
 *
 * Renders nothing while a deed can still be edited, so the badge means something
 * when it appears. The icon is decorative and the text carries the meaning — an icon
 * alone would be exactly the shape-only signal section 49 rules out.
 *
 * **The server decides this**, not the browser: `is_read_only` folds in both the
 * settled statuses and `locked_at`, so the interface cannot disagree with the
 * backend about what `CLAUDE.md` section 29 means.
 */
export function NotaryDeedReadOnlyBadge({ isReadOnly }: { isReadOnly: boolean }) {
  const t = useTranslations("notary");

  if (!isReadOnly) {
    return null;
  }

  return (
    <span className="border-border text-muted-foreground inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs">
      <Lock aria-hidden="true" className="size-3" />
      {t("readOnly")}
    </span>
  );
}
