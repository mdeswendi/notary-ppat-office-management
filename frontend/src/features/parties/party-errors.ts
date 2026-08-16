import { AxiosError } from "axios";

/**
 * Translation keys under the `parties.errors` namespace.
 */
export type PartyErrorKey =
  "forbidden" | "notFound" | "validation" | "tooManyAttempts" | "network" | "server";

/**
 * Map a failed Party Directory request onto a message the interface can show.
 *
 * Only the HTTP status is read. The response body never reaches the user, so a
 * Laravel exception or internal payload cannot leak through this path (CLAUDE.md
 * sections 32 and 48).
 *
 * `403` is an ordinary outcome rather than a fault: the directory refuses a
 * caller who holds neither `parties.view` nor `companies.view` at a usable
 * scope, and Data Scope is something the browser's permission list cannot fully
 * express (O-026).
 */
export function toPartyErrorKey(error: unknown): PartyErrorKey {
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
 * Why duplicate assistance could not run — never why it found something.
 *
 * A `403` here means the caller may not be told about matches on an identifier
 * they asked about, and a `429` means they have asked a great many times in a
 * minute. **Neither is evidence that a duplicate exists**, and the messages
 * these keys resolve to say so: inferring a match from a refusal would rebuild
 * the existence oracle the field permission exists to prevent (D-084).
 */
export type DuplicateCheckErrorKey = "forbidden" | "tooManyAttempts" | "unavailable";

export function toDuplicateCheckErrorKey(error: unknown): DuplicateCheckErrorKey {
  if (!(error instanceof AxiosError) || !error.response) {
    return "unavailable";
  }

  switch (error.response.status) {
    case 403:
      return "forbidden";
    case 429:
      return "tooManyAttempts";
    default:
      return "unavailable";
  }
}
