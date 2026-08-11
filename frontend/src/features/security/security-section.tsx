import type { ReactNode } from "react";

/**
 * One bordered block on the Account Security page.
 *
 * Shared so the four concerns — password, email, two-factor, sessions — read as
 * one page rather than four designs. Matches the section shell the profile page
 * already uses, deliberately: this is the same kind of surface, and a person
 * moving between them should not have to relearn the layout.
 */
export function SecuritySection({
  title,
  description,
  children,
  id,
}: {
  title: string;
  description: string;
  children: ReactNode;
  id?: string;
}) {
  return (
    <section
      id={id}
      className="border-border bg-card flex scroll-mt-6 flex-col gap-4 rounded-lg border p-5"
    >
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-medium">{title}</h2>
        <p className="text-muted-foreground text-sm">{description}</p>
      </div>

      {children}
    </section>
  );
}

/**
 * A dismissible success line.
 *
 * `role="status"` rather than `role="alert"`: a confirmation is polite news, and
 * an assertive live region would interrupt a screen reader mid-sentence to say
 * something went right.
 */
export function SecurityNotice({ children }: { children: ReactNode }) {
  return (
    <p role="status" className="border-border bg-muted/40 rounded-md border px-3 py-2 text-sm">
      {children}
    </p>
  );
}

export function SecurityError({ children }: { children: ReactNode }) {
  return (
    <p
      role="alert"
      className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
    >
      {children}
    </p>
  );
}
