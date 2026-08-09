import type { ReactNode } from "react";

import { cn } from "@/lib/utils";

type PageContainerProps = {
  children: ReactNode;
  className?: string;
};

/**
 * Consistent content boundary for application pages.
 *
 * Owns horizontal padding and vertical rhythm only — 24px desktop page padding
 * per docs/04_UI_DESIGN_SYSTEM.md section 7. No width cap: operational tables
 * need the full content column on wide screens.
 *
 * Presentational. No data fetching, authentication, or permission logic.
 */
export function PageContainer({ children, className }: PageContainerProps) {
  return <div className={cn("flex flex-col gap-6 px-4 py-6 sm:px-6", className)}>{children}</div>;
}
