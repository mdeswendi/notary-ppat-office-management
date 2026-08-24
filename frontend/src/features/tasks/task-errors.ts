import { AxiosError } from "axios";

/**
 * Translation keys under the `tasks.errors` namespace.
 */
export type TaskErrorKey =
  "forbidden" | "notFound" | "validation" | "conflict" | "tooManyAttempts" | "network" | "server";

/**
 * Map a failed Task request onto a message the interface can show.
 *
 * Only the HTTP status and the shape of a 422 are read. The response body never
 * reaches the user, so a Laravel exception or internal payload cannot leak through
 * this path (`CLAUDE.md` sections 32 and 48).
 *
 * **`422` is split in two, because the two cases need different words.** A field
 * error — a title that is too long, an assignee in another Office — tells somebody
 * to check what they typed. A status refusal — completing a finished task,
 * deleting one still in flight — tells them the act is not available from where
 * the task is, which has nothing to do with their input. One message for both
 * would send people looking for a typo that is not there.
 *
 * `403` and `404` are ordinary outcomes rather than faults: Task access depends on
 * a Data Scope the browser's permission list cannot express (O-026), and an
 * unreachable Task answers 404 by design, because telling it apart from a
 * nonexistent one would confirm the record exists somewhere the caller may not
 * look.
 */
export function toTaskErrorKey(error: unknown): TaskErrorKey {
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
 * Used where the server knows something the client cannot: whether an assignee is
 * active and in this Office, and whether a Project or Matter is reachable. The
 * backend answers each with one indistinguishable message so no endpoint becomes a
 * probe, and the wording shown to the user stays translated and ours.
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
