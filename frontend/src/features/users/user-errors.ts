import { AxiosError } from "axios";

/**
 * Translation keys under the `users.errors` namespace.
 */
export type UserErrorKey = "forbidden" | "selfDisable" | "notFound" | "network" | "server";

/**
 * Map a failed user request onto a message the interface can show.
 *
 * Only the HTTP status is read; the response body never reaches the user, so a
 * Laravel exception or internal payload cannot leak through this path
 * (CLAUDE.md sections 32 and 48).
 *
 * `403` is expected rather than exceptional. User administration depends on a
 * canonical `users.*` permission at a Data Scope the browser's permission list
 * cannot express (O-026), so the page asks the backend and handles the refusal
 * instead of guessing in advance.
 *
 * `409` has one meaning on these endpoints: the administrator tried to disable
 * their own account.
 *
 * `422` is absent deliberately — validation errors are field-level and are fed
 * back into the form.
 */
export function toUserErrorKey(error: unknown): UserErrorKey {
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
    case 409:
      return "selfDisable";
    default:
      return "server";
  }
}

/**
 * Field errors Laravel rejected, as a map of field name to a translation key
 * this feature owns.
 *
 * The server's messages are English strings and are never displayed. Only which
 * fields failed is read; the client schema already covers shape and length, so
 * a field that reaches the server and still fails has failed a rule only the
 * server can check — uniqueness for `email`, and assignability for `office_id`.
 */
export function rejectedFields(error: unknown): string[] {
  if (!(error instanceof AxiosError) || error.response?.status !== 422) {
    return [];
  }

  const errors = (error.response.data as { errors?: Record<string, string[]> } | undefined)?.errors;

  return errors ? Object.keys(errors) : [];
}
