import type { ReactNode } from "react";

import { cn } from "@/lib/utils";

type PageContainerProps = {
  children: ReactNode;
  className?: string;
};

/**
 * Consistent content boundary for application pages.
 *
 * Owns horizontal padding, vertical rhythm, and a restrained page entrance —
 * 24px desktop page padding per docs/04_UI_DESIGN_SYSTEM.md section 7. No width
 * cap: operational tables need the full content column on wide screens. Motion
 * is disabled automatically when the user prefers reduced motion.
 *
 * Presentational. No data fetching, authentication, or permission logic.
 */
export function PageContainer({ children, className }: PageContainerProps) {
  return (
    <div
      className={cn(
        "animate-in fade-in slide-in-from-bottom-1 flex w-full min-w-0 flex-col gap-6 px-4 py-6 duration-300 ease-out motion-reduce:animate-none sm:px-6 [&>*]:min-w-0",
        className,
      )}
    >
      {children}
    </div>
  );
}
