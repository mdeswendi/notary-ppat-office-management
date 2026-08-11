import { AxiosError } from "axios";

/**
 * Translation keys under the `individuals.errors` namespace.
 */
export type IndividualErrorKey =
  "forbidden" | "notFound" | "validation" | "tooManyAttempts" | "network" | "server";

/**
 * Map a failed Individual request onto a message the interface can show.
 *
 * Only the HTTP status is read. The response body never reaches the user, so a
 * Laravel exception or internal payload cannot leak through this path (CLAUDE.md
 * sections 32 and 48) — which matters more here than elsewhere, because a failed
 * identity request is one whose error text is most likely to mention something
 * sensitive.
 *
 * `403` and `404` are both ordinary outcomes rather than faults: Party access
 * depends on a Data Scope the browser's permission list cannot express (O-026),
 * and a wrong-subtype or archived record answers 404 by design.
 *
 * `429` gets its own message because sensitive reveal is rate limited, and
 * "something went wrong" would be a poor description of "you have looked at a
 * lot of identity data in one minute".
 */
export function toIndividualErrorKey(error: unknown): IndividualErrorKey {
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
 * Which fields Laravel rejected, as field names only.
 *
 * The server's messages are English strings and are never displayed; only which
 * fields failed is read, so the interface can mark them while the wording stays
 * translated and under our control.
 */
export function rejectedIndividualFields(error: unknown): string[] {
  if (!(error instanceof AxiosError) || error.response?.status !== 422) {
    return [];
  }

  const errors = (error.response.data as { errors?: Record<string, string[]> } | undefined)?.errors;

  return errors ? Object.keys(errors) : [];
}
