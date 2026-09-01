import type { ComponentProps } from "react";

import { cn } from "@/lib/utils";

/**
 * The dropdown every filter and every closed-vocabulary field uses.
 *
 * ## A native `<select>`, deliberately
 *
 * Base UI ships a Select, and this is not it. That one is a custom listbox: its
 * own keyboard model, its own portal, its own scroll containment. A native
 * control costs none of that, opens as the operating system's own dropdown, and
 * is what fifty-six places in this application already are. Replacing the
 * element is a behavioural change nobody asked for; giving it one class list is
 * not.
 *
 * ## The appearance is the one already in use
 *
 * Forty-six of those fifty-six carried exactly this class string, copied. The
 * component keeps it, so adopting it changes no pixel on any of them.
 *
 * **That is not the same as saying it is right.** Two things were found while
 * consolidating, and both are recorded rather than quietly fixed:
 *
 * 1. `Input` beside it is `h-8` and `rounded-lg`; this is `h-9` and
 *    `rounded-md`. In every filter row the two sit together under `items-end`,
 *    so the dropdown stands 4px taller than the search box next to it.
 * 2. `docs/04_UI_DESIGN_SYSTEM.md` section 8 puts an input at 6–8px radius.
 *    `rounded-md` is 8px and within it; `Input`'s `rounded-lg` is 10px and is
 *    not. So the two controls disagree, and the one that matches the
 *    specification is this one.
 *
 * Reconciling them is a visual decision across every form in the product, which
 * is why it is not taken here. It is now a one-line change in this file rather
 * than a forty-six-file change, which was the point of consolidating first.
 *
 * Presentational. It renders what it is given and decides nothing.
 */
export function Select({ className, ...props }: ComponentProps<"select">) {
  return (
    <select
      data-slot="select"
      className={cn(
        "border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none",
        className,
      )}
      {...props}
    />
  );
}
