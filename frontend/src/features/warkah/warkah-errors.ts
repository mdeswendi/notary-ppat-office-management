import { AxiosError } from "axios";

/**
 * Translation keys under the `warkah.errors` namespace.
 */
export type WarkahErrorKey =
  "forbidden" | "notFound" | "validation" | "conflict" | "tooManyAttempts" | "network" | "server";

/**
 * Map a failed Warkah request onto a message the interface can show.
 *
 * Only the HTTP status and the shape of a 422 are read. The response body never reaches
 * the user, so a Laravel exception or internal payload cannot leak through this path
 * (`CLAUDE.md` sections 32 and 48).
 *
 * **`404` is the interesting one here**, and the section treats it separately rather
 * than as an error at all: a deed whose office has not started a bundle answers 404,
 * and so does a deed the caller may not reach. The two are indistinguishable by design
 * (the M6.3 convention), so the section renders an empty state and offers the control
 * that would start one — which the capability flags then decide.
 *
 * `403` is an ordinary outcome rather than a fault: Warkah access is its own capability
 * family, separate from `ppat.deeds.*`, so a reader who can open the deed and not its
 * supporting bundle is a configuration somebody chose.
 */
export function toWarkahErrorKey(error: unknown): WarkahErrorKey {
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
      return hasAnyFieldError(error) ? "validation" : "conflict";
    case 429:
      return "tooManyAttempts";
    default:
      return "server";
  }
}

/**
 * Whether the failure was a 404 — *nothing started here*, rather than a fault.
 */
export function isNotStarted(error: unknown): boolean {
  return error instanceof AxiosError && error.response?.status === 404;
}

/**
 * Whether Laravel returned a validation error for a field.
 *
 * Used where the server knows something the client cannot: whether a Party or a
 * Document is reachable. Both answer one indistinguishable message for an unreachable,
 * wrong-Office or nonexistent id, so no endpoint becomes a way to discover what exists,
 * and the wording shown to the user stays translated and ours.
 */
export function hasFieldError(error: unknown, field: string): boolean {
  return field in fieldErrors(error);
}

function hasAnyFieldError(error: unknown): boolean {
  return Object.keys(fieldErrors(error)).length > 0;
}

function fieldErrors(error: unknown): Record<string, string[]> {
  if (!(error instanceof AxiosError) || error.response?.status !== 422) {
    return {};
  }

  const data = error.response.data as { errors?: Record<string, string[]> } | undefined;

  return data?.errors ?? {};
}
