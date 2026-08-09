import { AxiosError } from "axios";

/**
 * Translation keys under the `auth.errors` namespace.
 */
export type ApiErrorKey =
  | "invalidCredentials"
  | "sessionExpired"
  | "tooManyAttempts"
  | "unauthenticated"
  | "network"
  | "server";

/**
 * Map a failed request onto a message the interface can show.
 *
 * Only the HTTP status is inspected. The response body is never surfaced, so a
 * Laravel exception, stack trace, or internal payload cannot reach the user
 * through this path — see CLAUDE.md sections 32 and 48.
 *
 * On the login form a 422 always means the credentials were rejected: the
 * shape of the request is already guaranteed by the client schema.
 */
export function toApiErrorKey(error: unknown): ApiErrorKey {
  if (!(error instanceof AxiosError)) {
    return "server";
  }

  if (!error.response) {
    return "network";
  }

  switch (error.response.status) {
    case 401:
      return "unauthenticated";
    case 419:
      return "sessionExpired";
    case 422:
      return "invalidCredentials";
    case 429:
      return "tooManyAttempts";
    default:
      return "server";
  }
}
