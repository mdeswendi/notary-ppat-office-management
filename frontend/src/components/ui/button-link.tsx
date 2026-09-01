import type { ComponentProps } from "react";
import type { VariantProps } from "class-variance-authority";

import { buttonVariants } from "@/components/ui/button";
import { Link } from "@/i18n/navigation";
import { cn } from "@/lib/utils";

/**
 * A navigation control that carries the button's styling.
 *
 * Deliberately not `<Button render={<Link />}>`. Base UI's Button assumes a real
 * `<button>` — its `nativeButton` prop defaults to true — so routing a link
 * through it warns in development on every such control. The escape hatch that
 * warning names, `nativeButton={false}`, is worse than the warning: Base UI then
 * puts `role="button"` on the anchor, so assistive technology announces a
 * control that navigates as one that acts on the current page.
 *
 * These controls navigate. They stay anchors, keep the link role, and take the
 * styling only — no button semantics, no keyboard handlers layered on top of
 * the ones an anchor already has.
 *
 * `Link` is the locale-aware one from `@/i18n/navigation` (CLAUDE.md section 7),
 * so the active locale segment travels with the href.
 */
function ButtonLink({
  className,
  variant = "default",
  size = "default",
  ...props
}: ComponentProps<typeof Link> & VariantProps<typeof buttonVariants>) {
  return (
    <Link
      data-slot="button"
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  );
}

export { ButtonLink };
