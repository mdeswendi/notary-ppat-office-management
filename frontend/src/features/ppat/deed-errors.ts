import { AxiosError } from "axios";

/**
 * Translation keys under the `ppat.errors` namespace.
 */
export type PpatErrorKey =
  "forbidden" | "notFound" | "validation" | "conflict" | "tooManyAttempts" | "network" | "server";

/**
 * Map a failed PPAT Deed request onto a message the interface can show.
 *
 * Only the HTTP status and the shape of a 422 are read. The response body never
 * reaches the user, so a Laravel exception or internal payload cannot leak through
 * this path (`CLAUDE.md` sections 32 and 48).
 *
 * **`422` is split in two, because the two cases need different words.** A field
 * error — a title too long, a Matter the caller cannot reach, a deed number another
 * deed already holds — tells somebody to check what they typed. A status refusal —
 * approving a deed nobody has reviewed, finalizing one that is not approved — tells
 * them the act is not available from where the deed is, which has nothing to do with
 * their input. One message for both would send people looking for a typo that is not
 * there.
 *
 * `403` and `404` are ordinary outcomes rather than faults: deed access depends on a
 * Data Scope the browser's permission list cannot express (O-026), and an unreachable
 * deed answers 404 by design, because telling it apart from a nonexistent one would
 * confirm the record exists somewhere the caller may not look.
 */
export function toPpatErrorKey(error: unknown): PpatErrorKey {
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
 * Whether Laravel returned a validation error for a field.
 *
 * Used where the server knows something the client cannot: whether a Matter is
 * reachable, of the PPAT domain, and in the actor's own Office, and whether another
 * deed in that Office already holds a number. The backend answers the first three
 * with one indistinguishable message so the endpoint never becomes a way to discover
 * which Matters exist, and the wording shown to the user stays translated and ours.
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
