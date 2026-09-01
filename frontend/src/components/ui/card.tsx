import type { ReactNode } from "react";

import { cn } from "@/lib/utils";

/**
 * The bordered block every page builds its sections from.
 *
 * One shell, three prior spellings. `SecuritySection` and `DashboardPanel` had
 * each grown their own copy — `SecuritySection`'s docblock says outright that it
 * "matches the section shell the profile page already uses, deliberately" — and
 * twenty-odd other places wrote the same class list by hand. Three copies of a
 * shell is how two of them quietly stop matching.
 *
 * The spacing is the one those places already agreed on rather than a new
 * opinion: 20px padding and a 16px gap, the 8-10px card radius of
 * `docs/04_UI_DESIGN_SYSTEM.md` section 8. Adopting this changes no pixel.
 *
 * A `<section>` like the markup it replaces. It takes no accessible name of its
 * own, so it is not a landmark — the `<h2>` inside labels it for a reader, which
 * is what the previous markup did too.
 *
 * Presentational. No data fetching, authentication, or permission logic.
 */
export function Card({
  children,
  className,
  id,
}: {
  children: ReactNode;
  className?: string;
  id?: string;
}) {
  return (
    <section
      id={id}
      className={cn("border-border bg-card flex flex-col gap-4 rounded-lg border p-5", className)}
    >
      {children}
    </section>
  );
}

/**
 * A Card's title, its optional sentence, and its optional action.
 *
 * **The layout switches on what it was given, and each branch reproduces markup
 * that already existed.** With no action the title block is a plain column, as
 * every detail page writes it. With an action it becomes a row: centred when
 * there is no description, which is the Dashboard's panel header, and top-
 * aligned when there is one, so a two-line title does not drag the button down
 * with it.
 *
 * `<h2>` because a Card is a section of a page whose `<h1>` is the PageHeader.
 */
export function CardHeader({
  title,
  description,
  action,
  className,
}: {
  title: ReactNode;
  description?: ReactNode;
  action?: ReactNode;
  className?: string;
}) {
  const text = (
    <div className="flex flex-col gap-1">
      <h2 className="text-base font-medium">{title}</h2>
      {description ? <p className="text-muted-foreground text-sm">{description}</p> : null}
    </div>
  );

  if (!action) {
    return className ? <div className={className}>{text}</div> : text;
  }

  return (
    <div
      className={cn(
        "flex flex-wrap justify-between gap-2",
        description ? "items-start" : "items-center",
        className,
      )}
    >
      {text}
      {action}
    </div>
  );
}
