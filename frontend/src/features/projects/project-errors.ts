import { AxiosError } from "axios";

/**
 * Translation keys under the `projects.errors` namespace.
 */
export type ProjectErrorKey =
  "forbidden" | "notFound" | "validation" | "tooManyAttempts" | "network" | "server";

/**
 * Map a failed Project request onto a message the interface can show.
 *
 * Only the HTTP status is read. The response body never reaches the user, so a
 * Laravel exception or internal payload cannot leak through this path (CLAUDE.md
 * sections 32 and 48).
 *
 * `403` and `404` are both ordinary outcomes rather than faults: Project access
 * depends on a Data Scope the browser's permission list cannot express (O-026),
 * and an archived Project answers 404 to ordinary view by design.
 */
export function toProjectErrorKey(error: unknown): ProjectErrorKey {
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
 * The first validation message Laravel returned for a field, if any.
 *
 * Used only where the server knows something the client cannot — assignee
 * eligibility is the case that matters: the interface cannot tell whether a user
 * is active or in the right Office without asking, and the backend deliberately
 * answers with one indistinguishable message so the endpoint is not a directory
 * probe. The wording shown to the user stays translated and ours.
 */
export function hasFieldError(error: unknown, field: string): boolean {
  if (!(error instanceof AxiosError) || error.response?.status !== 422) {
    return false;
  }

  const errors = (error.response.data as { errors?: Record<string, string[]> } | undefined)?.errors;

  return errors !== undefined && field in errors;
}
