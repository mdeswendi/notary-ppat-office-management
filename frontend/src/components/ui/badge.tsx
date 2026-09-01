import type { ReactNode } from "react";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

/**
 * The chip every status, priority and domain label is drawn as.
 *
 * Twenty-seven of them existed across nine feature files, each repeating the
 * same shell and each holding its tints as raw class strings. The shell is here
 * now; the tints have names.
 *
 * ## The tones are the ones already in use, not a new palette
 *
 * Six were in circulation and all six are kept. Two deserve a note, because they
 * look like drift and are not: `primary` and `primarySubtle` differ only in
 * border opacity, and across all nine files the stronger one marked work **in
 * flight** — `IN_PROGRESS`, `UNDER_REVIEW` — while the softer marked work that
 * had **settled** — `COMPLETED`, `VERIFIED`, `FINAL`. That pattern was already
 * consistent, so it is preserved and named rather than flattened. Naming it is
 * the whole point: as two nearly-identical class strings it survived only by
 * luck.
 *
 * ## Colour is never the information
 *
 * `CLAUDE.md` section 49: status must not rely on colour alone. Every badge
 * carries its own translated text, and the tint is a second cue. A reader who
 * cannot separate the tints still reads the status. The tints stay muted on
 * purpose — section 39 rules out the traffic-light palette a status chip usually
 * attracts, and a Project being `CANCELLED` is an ordinary operational fact.
 *
 * Presentational. It renders what it is given and decides nothing.
 */
const badgeVariants = cva("rounded-full border px-2 py-0.5 text-xs", {
  variants: {
    tone: {
      muted: "border-border text-muted-foreground",
      neutral: "border-border text-foreground",
      primary: "border-primary/40 text-primary",
      primarySubtle: "border-primary/30 text-primary",
      ppat: "border-ppat/40 text-ppat",

      /**
       * The two tones that fill their background, and they mark one thing: a
       * record that has reached its terminal state. `FINALIZED` on a Notarial
       * Deed, `FINALIZED` and `COMPLETE` on the PPAT side.
       *
       * They are their own tones rather than variants of `primary` and `ppat`
       * because the fill is what separates finished from in flight — a finalized
       * deed is a legal record that can no longer be edited, and that is worth
       * seeing at a glance.
       */
      primaryStrong: "border-primary bg-primary/5 text-primary",
      ppatStrong: "border-ppat bg-ppat/5 text-ppat",

      destructive: "border-destructive/40 text-destructive",
    },

    /**
     * Opt-in, because it changes how the chip sits on the text baseline.
     *
     * Only a badge that carries an icon beside its label needs it, and forcing
     * `inline-flex` on the other twenty-three would shift them all.
     */
    withIcon: {
      true: "inline-flex items-center gap-1",
      false: "",
    },
  },
  defaultVariants: {
    tone: "muted",
    withIcon: false,
  },
});

export function Badge({
  children,
  tone,
  withIcon,
  className,
  ...props
}: {
  children: ReactNode;
  className?: string;
} & VariantProps<typeof badgeVariants> &
  React.ComponentPropsWithoutRef<"span">) {
  return (
    <span className={cn(badgeVariants({ tone, withIcon }), className)} {...props}>
      {children}
    </span>
  );
}

export type BadgeTone = NonNullable<VariantProps<typeof badgeVariants>["tone"]>;

export { badgeVariants };
