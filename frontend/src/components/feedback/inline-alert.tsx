import type { ComponentPropsWithoutRef } from "react";
import { CircleCheck, Info, TriangleAlert } from "lucide-react";

import { cn } from "@/lib/utils";

const tones = {
  error: {
    icon: TriangleAlert,
    className: "border-destructive/30 bg-destructive/5 text-destructive",
    role: "alert",
  },
  info: {
    icon: Info,
    className: "border-border bg-muted/40 text-foreground",
    role: "status",
  },
  success: {
    icon: CircleCheck,
    className: "border-primary/30 bg-primary/5 text-primary",
    role: "status",
  },
} as const;

type InlineAlertProps = ComponentPropsWithoutRef<"div"> & {
  tone?: keyof typeof tones;
};

/** Compact feedback for a failed or completed action inside an existing surface. */
export function InlineAlert({
  tone = "error",
  className,
  children,
  role,
  ...props
}: InlineAlertProps) {
  const appearance = tones[tone];
  const Icon = appearance.icon;

  return (
    <div
      role={role ?? appearance.role}
      className={cn(
        "flex min-w-0 items-start gap-2 rounded-md border px-3 py-2 text-sm break-words",
        appearance.className,
        className,
      )}
      {...props}
    >
      <Icon aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  );
}
