import { AxiosError } from "axios";

/**
 * Translation keys under the `roles.errors` namespace.
 */
export type RoleErrorKey = "forbidden" | "assigned" | "notFound" | "network" | "server";

/**
 * Map a failed role request onto a message the interface can show.
 *
 * Only the HTTP status is read. The response body never reaches the user, so a
 * Laravel exception message, stack trace, or internal payload cannot leak
 * through this path (CLAUDE.md sections 32 and 48).
 *
 * `403` is expected rather than exceptional here: role administration needs a
 * canonical `roles.*` permission at the `ALL` Data Scope, and the permission
 * list the browser holds cannot express that condition — so the page asks and
 * handles the refusal, rather than guessing in advance and hiding itself.
 *
 * `409` has exactly one meaning on these endpoints: the role is still assigned.
 *
 * `422` is absent deliberately. Validation errors are field-level and are fed
 * back into the form, not shown as a page-level message.
 */
export function toRoleErrorKey(error: unknown): RoleErrorKey {
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
      return "assigned";
    default:
      return "server";
  }
}

/**
 * Did the backend reject the submitted role name?
 *
 * Laravel's standard validation shape is
 * `{ message, errors: { field: [messages] } }`
 * (docs/06_API_CONVENTIONS.md section 7). Only the presence of a `name` entry
 * is read — the messages themselves are English server strings and are never
 * displayed; the form shows its own translated text instead.
 *
 * The client schema already enforces "present" and "at most 255 characters", so
 * a request that reaches the server and still fails on `name` has failed the one
 * rule only the server can check: uniqueness.
 */
export function isNameRejected(error: unknown): boolean {
  if (!(error instanceof AxiosError) || error.response?.status !== 422) {
    return false;
  }

  const errors = (error.response.data as { errors?: Record<string, string[]> } | undefined)?.errors;

  return Array.isArray(errors?.name) && errors.name.length > 0;
}
