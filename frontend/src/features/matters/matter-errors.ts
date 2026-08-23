import { AxiosError } from "axios";

/**
 * Translation keys under the `matters.errors` namespace.
 */
export type MatterErrorKey =
  "forbidden" | "notFound" | "validation" | "tooManyAttempts" | "network" | "server";

/**
 * Map a failed Matter request onto a message the interface can show.
 *
 * Only the HTTP status is read. The response body never reaches the user, so a
 * Laravel exception or internal payload cannot leak through this path (CLAUDE.md
 * sections 32 and 48).
 *
 * `403` and `404` are both ordinary outcomes rather than faults. Matter access
 * depends on a Data Scope the browser's permission list cannot express (O-026),
 * and **a Matter of the other domain answers 404 by design** (D-101): a Notary
 * address handed a PPAT id behaves as though nothing is there, because a 403
 * would confirm the record exists in a domain the caller never named.
 */
export function toMatterErrorKey(error: unknown): MatterErrorKey {
  if (!(error instanceof AxiosError)) {
    return "server";
  }

  if (!error.response) {
    return "network";
  }

  switch (error.response.status) {
    case 403:
      return "forbidden";
    case 404:
      return "notFound";
    case 422:
      return "validation";
    case 429:
      return "tooManyAttempts";
    default:
      return "server";
  }
}

/**
 * Whether Laravel returned a validation error for a field.
 *
 * Used only where the server knows something the client cannot — assignee
 * eligibility and Service Type eligibility are the two cases that matter: the
 * interface cannot tell whether a user is active and in the right Office, or
 * whether a Service Type is still active and of this domain, without asking. The
 * backend answers both with one indistinguishable message so neither endpoint
 * becomes a probe, and the wording shown to the user stays translated and ours.
 */
export function hasFieldError(error: unknown, field: string): boolean {
  if (!(error instanceof AxiosError) || error.response?.status !== 422) {
    return false;
  }

  const errors = (error.response.data as { errors?: Record<string, string[]> } | undefined)?.errors;

  return errors !== undefined && field in errors;
}
