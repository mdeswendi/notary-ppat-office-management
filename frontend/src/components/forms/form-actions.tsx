import type { ComponentProps } from "react";

import { cn } from "@/lib/utils";

/**
 * The action row for full-page forms.
 *
 * Primary actions span the available width on phones for an easy touch target,
 * then return to compact office-desktop sizing from `sm` upward. Mini inline
 * forms deliberately do not use this component.
 */
export function FormActions({ className, ...props }: ComponentProps<"div">) {
  return (
    <div
      data-slot="form-actions"
      className={cn(
        "flex flex-col gap-2 pt-1 sm:flex-row sm:items-center [&>[data-slot=button]]:w-full sm:[&>[data-slot=button]]:w-auto",
        className,
      )}
      {...props}
    />
  );
}
