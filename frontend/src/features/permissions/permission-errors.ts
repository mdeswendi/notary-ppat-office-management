import { AxiosError } from "axios";

/**
 * Translation keys under the `permissions.errors` namespace.
 */
export type PermissionErrorKey =
  "forbidden" | "lastAdministrator" | "notFound" | "validation" | "network" | "server";

/**
 * Map a failed authorization-administration request onto a message.
 *
 * Only the status is read; server strings never reach the user.
 *
 * `409` has one meaning across these endpoints: the change would have left
 * nobody able to administer authorization, so it was rolled back. That is worth
 * saying plainly — an administrator who has just locked themselves out of the
 * permission system has no way back, which is precisely why the backend refuses.
 */
export function toPermissionErrorKey(error: unknown): PermissionErrorKey {
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
      return "lastAdministrator";
    case 422:
      return "validation";
    default:
      return "server";
  }
}
