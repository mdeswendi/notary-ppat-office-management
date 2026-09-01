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
 * ## It matches `Input`, and that is the point
 *
 * Consolidating first exposed a three-way split: forty-six dropdowns at 36px
 * tall with an 8px radius, two already matching `Input` at 32px and 10px, and
 * four with no fixed height at all. `Input` itself is 32px.
 *
 * So in every filter row — a search box and a dropdown side by side under
 * `items-end` — the dropdown stood 4px taller than the box beside it, on every
 * list in the product. That is the misalignment a reader sees without being able
 * to name it.
 *
 * This now carries `Input`'s own control appearance: same height, same radius,
 * same border token, same focus ring, same transparent fill so the two look
 * identical on a card and on the page. All fifty-two dropdowns are one control.
 *
 * **One thing is deliberately still open.** `docs/04_UI_DESIGN_SYSTEM.md` section
 * 8 puts an input at 6–8px radius, and `Input`'s `rounded-lg` is 10px — so the
 * pair now agree with each other and neither agrees with the specification.
 * Matching the shipped primitive was the fix for the visible defect; moving both
 * to 8px is a separate decision about the specification, and it is not taken
 * here.
 *
 * Presentational. It renders what it is given and decides nothing.
 */
export function Select({ className, ...props }: ComponentProps<"select">) {
  return (
    <select
      data-slot="select"
      className={cn(
        "border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30 h-8 rounded-lg border bg-transparent px-2.5 text-sm transition-colors outline-none focus-visible:ring-3 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:ring-3",
        className,
      )}
      {...props}
    />
  );
}
