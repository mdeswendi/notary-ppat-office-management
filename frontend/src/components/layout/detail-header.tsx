import type { ReactNode } from "react";

import { cn } from "@/lib/utils";

type DetailHeaderProps = {
  title: ReactNode;
  reference?: ReactNode;
  description?: ReactNode;
  badges?: ReactNode;
  actions?: ReactNode;
  children?: ReactNode;
  className?: string;
};

/**
 * Shared hierarchy for record detail pages.
 *
 * The identity stays readable when space is tight, while badges wrap beside the
 * title and actions move below it on phones. Capability checks and mutations
 * remain in the feature that supplies the action slot.
 */
export function DetailHeader({
  title,
  reference,
  description,
  badges,
  actions,
  children,
  className,
}: DetailHeaderProps) {
  return (
    <header className={cn("flex min-w-0 flex-col gap-3", className)}>
      <div className="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex min-w-0 flex-1 flex-col gap-1">
          {reference ? (
            <div className="text-muted-foreground min-w-0 font-mono text-xs break-all">
              {reference}
            </div>
          ) : null}

          <div className="flex min-w-0 flex-wrap items-center gap-2">
            <h1 className="min-w-0 text-2xl font-semibold tracking-tight break-words">{title}</h1>
            {badges ? <div className="flex flex-wrap items-center gap-2">{badges}</div> : null}
          </div>

          {description ? (
            <p className="text-muted-foreground min-w-0 text-sm break-words">{description}</p>
          ) : null}
        </div>

        {actions ? (
          <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:shrink-0 sm:justify-end">
            {actions}
          </div>
        ) : null}
      </div>

      {children}
    </header>
  );
}
