import type { ReactNode } from "react";

import { Card, CardHeader } from "@/components/ui/card";

/**
 * One bordered block on the Account Security page.
 *
 * Shared so the four concerns — password, email, two-factor, sessions — read as
 * one page rather than four designs. It now builds on the shared `Card` rather
 * than restating that shell: the resemblance to the profile page was already
 * deliberate, and a copy is how two deliberate resemblances drift apart.
 *
 * What remains here is what is genuinely its own — `scroll-mt-6`, so an anchored
 * jump from the account menu does not land the heading under the app header.
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
    <Card id={id} className="scroll-mt-6">
      <CardHeader title={title} description={description} />

      {children}
    </Card>
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
