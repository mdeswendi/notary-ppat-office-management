import { AxiosError } from "axios";

/**
 * Translation keys under the `properties.errors` namespace.
 */
export type PropertyErrorKey =
  "forbidden" | "notFound" | "validation" | "conflict" | "tooManyAttempts" | "network" | "server";

/**
 * Map a failed Property request onto a message the interface can show.
 *
 * Only the HTTP status and the shape of a 422 are read. The response body never
 * reaches the user, so a Laravel exception or internal payload cannot leak through this
 * path (`CLAUDE.md` sections 32 and 48).
 *
 * **`422` is split in two, because the two cases need different words.** A field error
 * — a reference another parcel already holds, a Party the caller cannot reach, a share
 * outside 0–100 — tells somebody to check what they typed. A state refusal — archiving
 * a parcel a running Matter still names — tells them the act is unavailable *because of
 * other records*, which has nothing to do with their input and clears by itself. One
 * message for both would send people looking for a typo that is not there.
 *
 * `403` and `404` are ordinary outcomes rather than faults: Property access depends on
 * a Data Scope the browser's permission list cannot express (O-026), an unreachable
 * parcel answers 404 by design, and an **archived** one answers 403 to every write
 * because archived-ness is a property of the record.
 */
export function toPropertyErrorKey(error: unknown): PropertyErrorKey {
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
 * Used where the server knows something the client cannot: whether a reference is free
 * within the Office, and whether a Party or a Property is reachable. The backend answers
 * reachability and non-existence with one indistinguishable message so no endpoint
 * becomes a way to discover what exists, and the wording shown to the user stays
 * translated and ours.
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
