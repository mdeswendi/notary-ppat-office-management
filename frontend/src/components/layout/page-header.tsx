import type { ReactNode } from "react";

import { cn } from "@/lib/utils";

type PageHeaderProps = {
  title: ReactNode;

  /** The sentence under the title. Optional: a few pages carry none. */
  description?: ReactNode;

  /**
   * The page's primary action, rendered on the title's row.
   *
   * `docs/04_UI_DESIGN_SYSTEM.md` section 12 puts it there. No page passes one
   * yet: every list keeps its create button inside the feature component, where
   * the `PermissionGuard` that decides whether to offer it also lives. Moving
   * those is a separate change with a real authorization surface to think about,
   * so the slot is here and empty rather than the layout being wrong.
   */
  actions?: ReactNode;

  /** Section 12's breadcrumb line. Nothing supplies one yet. */
  breadcrumb?: ReactNode;

  className?: string;
};

/**
 * The standard page header — `docs/04_UI_DESIGN_SYSTEM.md` section 12.
 *
 * ```text
 * Breadcrumb
 * Page Title                    Primary Action
 * Subtitle
 * ```
 *
 * It replaces the same four lines of markup repeated across 56 pages. That
 * repetition was not merely untidy: it is how spacing and type drift apart one
 * page at a time, and a drifting layout is what makes an application look
 * unfinished even when every screen is individually fine.
 *
 * **The title keeps its own element when there is no action.** Wrapping a lone
 * `<h1>` in a flex row would make it a flex item and change how a long title
 * wraps — a real visual change on the pages that pass no action, which is all of
 * them today. The row appears only when it has two things to separate.
 *
 * Presentational. No data fetching, authentication, or permission logic —
 * `PageContainer` next to it holds the same line.
 */
export function PageHeader({
  title,
  description,
  actions,
  breadcrumb,
  className,
}: PageHeaderProps) {
  const heading = <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>;

  return (
    <header className={cn("flex flex-col gap-1", className)}>
      {breadcrumb}

      {actions ? (
        <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
          {heading}
          <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>
        </div>
      ) : (
        heading
      )}

      {description ? <p className="text-muted-foreground">{description}</p> : null}
    </header>
  );
}
