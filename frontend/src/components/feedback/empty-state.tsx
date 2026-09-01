import type { ReactNode } from "react";

import { cn } from "@/lib/utils";

type EmptyStateProps = {
  /** Already-translated heading. Callers pass `t(...)`, never a literal. */
  title: string;
  /** Already-translated explanation, safe for end users. */
  description: string;
  /** Optional way forward — typically the control that would create the first record. */
  action?: ReactNode;
  className?: string;
};

/**
 * Nothing here yet, said calmly.
 *
 * ## Why this exists
 *
 * Seventeen empty lists were rendering through `BaseErrorState`, which carries
 * `role="alert"` and a red `TriangleAlert`. Two things followed, and neither was
 * intended:
 *
 * A screen reader announced *"Belum ada Perusahaan"* **assertively**, interrupting
 * whatever it was reading. `role="alert"` is for something that went wrong and
 * needs attention now; an empty list is neither.
 *
 * And every list in a new deployment showed a destructive-red warning. An office
 * opening the product for the first time — before a single Party exists — met a
 * red triangle on Projects, on Matters, on Documents, on Tasks. Nothing was
 * broken. The interface simply had no way to say so.
 *
 * ## What it does instead
 *
 * The same card, the same words, no alarm: no `role="alert"`, no icon, no
 * destructive colour. An empty list is an ordinary state and now reads as one —
 * `CLAUDE.md` section 39 asks for calm, and calm is not only about spacing.
 *
 * Like `BaseErrorState` beside it, this accepts only pre-translated copy and has
 * no way to render an exception, payload, or backend detail, so nothing can leak
 * into the interface through it (sections 32 and 48).
 */
export function EmptyState({ title, description, action, className }: EmptyStateProps) {
  return (
    <div
      className={cn(
        "border-border bg-card flex flex-col items-start gap-2 rounded-lg border p-6",
        className,
      )}
    >
      <h2 className="text-base font-medium">{title}</h2>
      <p className="text-muted-foreground max-w-prose text-sm">{description}</p>
      {action ? <div className="pt-1">{action}</div> : null}
    </div>
  );
}
