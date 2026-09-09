import type { ReactNode } from "react";
import { Scale } from "lucide-react";

import { LocaleSwitcher } from "@/components/locale-switcher";
import { Card } from "@/components/ui/card";

type AuthShellProps = {
  officeLabel: string;
  title: string;
  description?: string;
  children: ReactNode;
};

/** Shared, focused frame for every unauthenticated account flow. */
export function AuthShell({ officeLabel, title, description, children }: AuthShellProps) {
  return (
    <main className="bg-muted/30 flex min-h-svh items-center justify-center px-4 py-8 sm:py-12">
      <div className="flex w-full max-w-md flex-col gap-5">
        <div className="flex items-center justify-center gap-2.5">
          <span
            aria-hidden="true"
            className="bg-primary text-primary-foreground grid size-8 shrink-0 place-items-center rounded-lg"
          >
            <Scale className="size-4" />
          </span>
          <span className="text-sm font-semibold tracking-tight">{officeLabel}</span>
        </div>

        <Card className="gap-6 p-5 sm:p-6">
          <header className="flex flex-col gap-1.5">
            <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
            {description ? (
              <p className="text-muted-foreground text-sm break-words">{description}</p>
            ) : null}
          </header>

          {children}
        </Card>

        <div className="flex justify-center">
          <LocaleSwitcher />
        </div>
      </div>
    </main>
  );
}
