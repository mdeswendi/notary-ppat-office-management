import { AxiosError } from "axios";

/**
 * Translation keys under the `documents.errors` namespace.
 */
export type DocumentErrorKey =
  | "forbidden"
  | "notFound"
  | "validation"
  | "conflict"
  | "tooLarge"
  | "tooManyAttempts"
  | "network"
  | "server";

/**
 * Map a failed Document request onto a message the interface can show.
 *
 * Only the HTTP status is read. The response body never reaches the user, so a
 * Laravel exception or internal payload cannot leak through this path
 * (`CLAUDE.md` sections 32 and 48).
 *
 * `403` and `404` are both ordinary outcomes rather than faults. Document access
 * depends on a Data Scope the browser's permission list cannot express (O-026),
 * and **an unreachable document answers 404 by design**: telling it apart from a
 * nonexistent one would confirm the record exists somewhere the caller may not
 * look.
 *
 * **`422` is split in two, because the two cases need different words.** A
 * document that cannot be verified from its current status is not the same problem
 * as a file that is too large or of the wrong type, and one message for both would
 * tell somebody to check their file when the file was fine. `413` is folded into
 * the size case: a file that exceeds the *server's* limit never reaches Laravel's
 * validator at all, so it answers 413 rather than 422 and would otherwise fall
 * through to the generic message.
 */
export function toDocumentErrorKey(error: unknown): DocumentErrorKey {
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
    case 413:
      return "tooLarge";
    case 422:
      return hasFieldError(error, "file") ? "validation" : "conflict";
    case 429:
      return "tooManyAttempts";
    default:
      return "server";
  }
}

/**
 * Whether Laravel returned a validation error for a field.
 *
 * Used where the server knows something the client cannot: whether a related
 * Party, Project or Matter is reachable in this Office, and whether a file's
 * *actual* type — detected from its contents rather than its extension — is
 * allowed. The backend answers the first with one indistinguishable message per
 * field so the endpoint does not become a probe, and the wording shown to the user
 * stays translated and ours.
 */
export function hasFieldError(error: unknown, field: string): boolean {
  if (!(error instanceof AxiosError) || error.response?.status !== 422) {
    return false;
  }

  const errors = (error.response.data as { errors?: Record<string, string[]> } | undefined)?.errors;

  return errors !== undefined && field in errors;
}
