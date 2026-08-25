"use client";

import { Lock } from "lucide-react";
import { useTranslations } from "next-intl";

import type { PpatDeedStatus } from "@/types/ppat";

/**
 * PPAT Deed status and type, rendered as labelled badges (M7.2, D-121).
 *
 * **Status must not rely on colour alone** (`CLAUDE.md` section 49): every badge
 * carries its translated text, and the tint is a secondary cue rather than the
 * information itself. A reader who cannot distinguish the tints still reads the
 * status.
 *
 * **The accent is the PPAT teal, used lightly.** `--ppat` has sat in the token set
 * since M0.6 with nothing referencing it; this is its first use, and section 42 is
 * precise about the dose — *"badges, icons, subtle section markers"*, and *"do not
 * turn the entire PPAT interface green"*. So `FINALIZED` gets the one emphasis,
 * because a finalized deed is a legal record that can no longer be edited and that is
 * worth seeing at a glance, and everything else stays neutral. The Notary equivalent
 * does the same thing with navy.
 *
 * **`VOID` and `SUPERSEDED` are rendered and never offered.** No code path produces
 * them — *"what correction mechanisms are permitted after finalization?"* is open
 * question nine — but a deed written directly to the database could carry one, and a
 * badge that refused to render a canonical status would show a blank where a legal
 * state belongs. What the interface does not do is offer a control claiming to set one.
 */

const STATUS_TINT: Record<PpatDeedStatus, string> = {
  DRAFT: "border-border text-foreground",
  UNDER_REVIEW: "border-ppat/40 text-ppat",
  APPROVED: "border-ppat/40 text-ppat",
  FINALIZED: "border-ppat bg-ppat/5 text-ppat",
  VOID: "border-border text-muted-foreground",
  SUPERSEDED: "border-border text-muted-foreground",
};

export function PpatDeedStatusBadge({ status }: { status: PpatDeedStatus }) {
  const t = useTranslations("ppat");

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
 * `03_DATABASE_ERD.md` gives it no vocabulary, M7 seeds no catalogue, and the
 * examples elsewhere in the canonical set are prose. Rendering `AJB` as "Akta Jual
 * Beli" here would require a mapping this milestone has no authority to write — and
 * inventing legal translations is exactly what `CLAUDE.md` section 9 forbids. The
 * office's own code is shown verbatim.
 */
export function PpatDeedTypeBadge({ code }: { code: string | null }) {
  const t = useTranslations("ppat");

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
 * Renders nothing while a deed can still be edited, so the badge means something when
 * it appears. The icon is decorative and the text carries the meaning — an icon alone
 * would be exactly the shape-only signal section 49 rules out.
 *
 * **The server decides this**, not the browser: `is_read_only` folds in both the
 * settled statuses and `locked_at`, so the interface cannot disagree with the backend
 * about what `CLAUDE.md` section 29 means.
 */
export function PpatDeedReadOnlyBadge({ isReadOnly }: { isReadOnly: boolean }) {
  const t = useTranslations("ppat");

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
