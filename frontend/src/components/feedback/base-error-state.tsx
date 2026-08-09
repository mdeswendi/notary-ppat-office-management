import type { ReactNode } from "react";
import { TriangleAlert } from "lucide-react";

import { cn } from "@/lib/utils";

type BaseErrorStateProps = {
  /** Already-translated heading. Callers pass `t(...)`, never a literal. */
  title: string;
  /** Already-translated explanation, safe for end users. */
  description: string;
  /** Optional caller-supplied action, typically retry or a way back. */
  action?: ReactNode;
  className?: string;
};

/**
 * Neutral error presentation.
 *
 * Deliberately accepts only pre-translated, caller-controlled copy. It has no
 * way to render an exception, stack trace, response payload, or any backend
 * detail, so those cannot leak into the interface through this component —
 * see CLAUDE.md sections 32 and 48.
 *
 * Status is not carried by colour alone: the icon is paired with a heading and
 * explanatory text.
 */
export function BaseErrorState({ title, description, action, className }: BaseErrorStateProps) {
  return (
    <div
      role="alert"
      className={cn(
        "border-border bg-card flex flex-col items-start gap-3 rounded-lg border p-6",
        className,
      )}
    >
      <TriangleAlert aria-hidden="true" className="text-destructive size-5 shrink-0" />
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-medium">{title}</h2>
        <p className="text-muted-foreground max-w-prose text-sm">{description}</p>
      </div>
      {action ? <div className="pt-1">{action}</div> : null}
    </div>
  );
}
